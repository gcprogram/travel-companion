/**
 * Client-side video compression via WebCodecs + the vendored mp4-muxer
 * (public/assets/js/vendor/mp4-muxer.js, must be loaded first).
 *
 * Approach: decode the source through an off-screen <video> element and
 * grab frames via canvas (avoids needing a container demuxer), decode audio
 * in one shot via AudioContext.decodeAudioData, re-encode both through
 * WebCodecs at a fixed target size/bitrate, and mux into an MP4. Prototyped
 * and verified end-to-end (encode + mux + full playback) in a real browser
 * before wiring into the app.
 *
 * Exposed as window.VideoCompress = { isSupported, MAX_DURATION_SECONDS, compress }.
 */
(function () {
  const TARGET_MAX_EDGE = 1280;
  const TARGET_FPS = 24;
  const VIDEO_BITRATE = 2_000_000;
  const AUDIO_BITRATE = 128_000;
  const MAX_DURATION_SECONDS = 120;

  function isSupported() {
    return !!(
      window.VideoEncoder &&
      window.VideoDecoder &&
      window.AudioEncoder &&
      window.VideoFrame &&
      window.AudioData &&
      window.OffscreenCanvas &&
      window.Mp4Muxer &&
      HTMLVideoElement.prototype.requestVideoFrameCallback
    );
  }

  function targetSize(sourceWidth, sourceHeight) {
    const longEdge = Math.max(sourceWidth, sourceHeight);
    if (longEdge <= TARGET_MAX_EDGE) {
      // Encoders want even dimensions; never upscale, just round down to even.
      return { width: sourceWidth - (sourceWidth % 2), height: sourceHeight - (sourceHeight % 2) };
    }
    const scale = TARGET_MAX_EDGE / longEdge;
    const width = Math.round(sourceWidth * scale / 2) * 2;
    const height = Math.round(sourceHeight * scale / 2) * 2;
    return { width, height };
  }

  function seekTo(video, time) {
    return new Promise((resolve) => {
      const onSeeked = () => { video.removeEventListener('seeked', onSeeked); resolve(); };
      video.addEventListener('seeked', onSeeked);
      video.currentTime = time;
    });
  }

  /**
   * @param {File} file
   * @param {{onProgress?: (fraction: number) => void}} [options]
   * @returns {Promise<{blob: Blob, width: number, height: number, duration: number}>}
   */
  async function compress(file, options) {
    const onProgress = (options && options.onProgress) || function () {};
    const arrayBuffer = await file.arrayBuffer();

    const video = document.createElement('video');
    video.src = URL.createObjectURL(file);
    video.muted = true;
    await new Promise((resolve, reject) => {
      video.onloadedmetadata = resolve;
      video.onerror = () => reject(new Error('video_load_failed'));
    });

    const duration = video.duration;
    if (!isFinite(duration) || duration <= 0) {
      throw new Error('video_load_failed');
    }
    if (duration > MAX_DURATION_SECONDS) {
      throw new Error('video_too_long');
    }

    const { width: targetWidth, height: targetHeight } = targetSize(video.videoWidth, video.videoHeight);

    let audioBuffer = null;
    try {
      audioBuffer = await new AudioContext().decodeAudioData(arrayBuffer.slice(0));
    } catch (e) {
      audioBuffer = null; // Silent video is acceptable; re-encoding still proceeds.
    }

    // Real encoder support (codec/resolution/bitrate combo) can differ from
    // plain API presence, especially on phones with limited hardware
    // encoders — check before committing, so unsupported devices get a
    // specific error instead of a mid-encode crash.
    const videoConfig = {
      codec: 'avc1.42001f',
      width: targetWidth,
      height: targetHeight,
      bitrate: VIDEO_BITRATE,
      framerate: TARGET_FPS,
    };
    const videoSupport = await VideoEncoder.isConfigSupported(videoConfig);
    if (!videoSupport.supported) {
      throw new Error('codec_unsupported');
    }

    let audioConfig = null;
    if (audioBuffer) {
      audioConfig = {
        codec: 'mp4a.40.2',
        sampleRate: audioBuffer.sampleRate,
        numberOfChannels: audioBuffer.numberOfChannels,
        bitrate: AUDIO_BITRATE,
      };
      const audioSupport = await AudioEncoder.isConfigSupported(audioConfig);
      if (!audioSupport.supported) {
        audioBuffer = null;
        audioConfig = null; // Fall back to a silent video rather than failing outright.
      }
    }

    const muxer = new Mp4Muxer.Muxer({
      target: new Mp4Muxer.ArrayBufferTarget(),
      video: { codec: 'avc', width: targetWidth, height: targetHeight },
      audio: audioConfig
        ? { codec: 'aac', numberOfChannels: audioConfig.numberOfChannels, sampleRate: audioConfig.sampleRate }
        : undefined,
      fastStart: 'in-memory',
    });

    // Encoder "error" callbacks — and our own output callbacks below, since
    // muxer.addVideoChunk()/addAudioChunk() can themselves throw — fire
    // asynchronously outside this function's call stack, so throwing
    // directly inside them would just be an unhandled rejection, not a
    // rejection of this compress() promise. Stash it instead and check
    // after every await point so it surfaces properly.
    let encoderError = null;
    const onEncoderError = (e) => { encoderError = e; };

    const videoEncoder = new VideoEncoder({
      output: (chunk, meta) => {
        try {
          muxer.addVideoChunk(chunk, meta);
        } catch (e) {
          onEncoderError(e);
        }
      },
      error: onEncoderError,
    });
    videoEncoder.configure(videoConfig);

    let audioEncoder = null;
    if (audioConfig) {
      audioEncoder = new AudioEncoder({
        output: (chunk, meta) => {
          try {
            muxer.addAudioChunk(chunk, meta);
          } catch (e) {
            onEncoderError(e);
          }
        },
        error: onEncoderError,
      });
      audioEncoder.configure(audioConfig);
    }

    const canvas = new OffscreenCanvas(targetWidth, targetHeight);
    const ctx = canvas.getContext('2d');
    const frameCount = Math.max(1, Math.round(duration * TARGET_FPS));
    const frameDurationUs = Math.round(1e6 / TARGET_FPS);

    video.pause();
    for (let i = 0; i < frameCount; i++) {
      const t = i / TARGET_FPS;
      await seekTo(video, t);
      if (encoderError) {
        throw encoderError;
      }
      ctx.drawImage(video, 0, 0, targetWidth, targetHeight);
      // duration is required — without it, EncodedVideoChunk.duration comes out
      // null and mp4-muxer rejects every chunk (only surfacing at finalize()).
      const frame = new VideoFrame(canvas, { timestamp: Math.round(t * 1e6), duration: frameDurationUs });
      videoEncoder.encode(frame, { keyFrame: i % (TARGET_FPS * 2) === 0 });
      frame.close();
      if (videoEncoder.encodeQueueSize > 4) {
        await new Promise((resolve) => { videoEncoder.ondequeue = resolve; });
      }
      onProgress((i / frameCount) * 0.9);
    }
    await videoEncoder.flush();
    videoEncoder.close();
    if (encoderError) {
      throw encoderError;
    }

    if (audioBuffer && audioEncoder) {
      const chunkFrames = Math.round(audioBuffer.sampleRate * 0.05);
      const totalFrames = audioBuffer.length;
      const channels = audioBuffer.numberOfChannels;
      for (let offset = 0; offset < totalFrames; offset += chunkFrames) {
        const n = Math.min(chunkFrames, totalFrames - offset);
        const interleaved = new Float32Array(n * channels);
        for (let ch = 0; ch < channels; ch++) {
          const data = audioBuffer.getChannelData(ch);
          for (let i = 0; i < n; i++) {
            interleaved[i * channels + ch] = data[offset + i];
          }
        }
        const audioData = new AudioData({
          format: 'f32',
          sampleRate: audioBuffer.sampleRate,
          numberOfFrames: n,
          numberOfChannels: channels,
          timestamp: Math.round((offset / audioBuffer.sampleRate) * 1e6),
          data: interleaved,
        });
        audioEncoder.encode(audioData);
        audioData.close();
      }
      await audioEncoder.flush();
      audioEncoder.close();
      if (encoderError) {
        throw encoderError;
      }
    }
    onProgress(1);

    muxer.finalize();
    URL.revokeObjectURL(video.src);

    return {
      blob: new Blob([muxer.target.buffer], { type: 'video/mp4' }),
      width: targetWidth,
      height: targetHeight,
      duration,
    };
  }

  window.VideoCompress = { isSupported, compress, MAX_DURATION_SECONDS };
})();
