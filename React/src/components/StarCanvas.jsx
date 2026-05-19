import { useEffect, useRef } from 'react';

function random(min, max) {
  return Math.random() * (max - min) + min;
}

export default function StarCanvas({ id = 'star-canvas', className = '', variant = 'public' }) {
  const canvasRef = useRef(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return undefined;

    const ctx = canvas.getContext('2d');
    if (!ctx) return undefined;

    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let width = 0;
    let height = 0;
    let stars = [];
    let streaks = [];
    let rafId = null;

    class Star {
      constructor() {
        this.reset(true);
      }

      reset(anywhere = false) {
        this.x = anywhere ? random(0, width) : random(-80, width + 80);
        this.y = anywhere ? random(0, height) : random(-80, height + 80);
        this.baseRadius = random(0.55, variant === 'panel' ? 1.9 : 1.8);
        this.radius = this.baseRadius;
        this.vx = random(variant === 'panel' ? -0.035 : -0.10, variant === 'panel' ? 0.035 : 0.10);
        this.vy = random(variant === 'panel' ? -0.02 : -0.06, variant === 'panel' ? 0.055 : 0.12);
        this.alpha = random(0.25, 0.95);
        this.phase = random(0, Math.PI * 2);
        this.twinkle = random(0.006, 0.018);
        this.kind = Math.random() > 0.78 ? 'violet' : 'cyan';
      }

      update() {
        this.phase += this.twinkle;
        this.radius = this.baseRadius + (variant === 'panel' ? Math.sin(this.phase) * 0.22 : 0);
        this.alpha = 0.30 + Math.abs(Math.sin(this.phase)) * 0.60;

        if (!reduceMotion) {
          this.x += this.vx;
          this.y += this.vy;
        }

        if (this.x < -90) this.x = width + 90;
        if (this.x > width + 90) this.x = -90;
        if (this.y < -90) this.y = height + 90;
        if (this.y > height + 90) this.y = -90;
      }

      draw() {
        const rgb = this.kind === 'violet' ? '168, 139, 255' : '121, 232, 255';
        ctx.beginPath();
        ctx.fillStyle = `rgba(${rgb}, ${this.alpha})`;
        ctx.shadowBlur = this.baseRadius > 1.35 ? 12 : 0;
        ctx.shadowColor = `rgba(${rgb}, 0.75)`;
        ctx.arc(this.x, this.y, Math.max(0.35, this.radius), 0, Math.PI * 2);
        ctx.fill();
        ctx.shadowBlur = 0;
      }
    }

    class Streak {
      constructor() {
        this.reset();
      }

      reset() {
        this.x = random(width * 0.35, width + 320);
        this.y = random(-height * 0.35, height * 0.45);
        this.length = random(variant === 'panel' ? 130 : 90, variant === 'panel' ? 260 : 190);
        this.speed = random(variant === 'panel' ? 7 : 8, variant === 'panel' ? 13 : 16);
        this.wait = random(variant === 'panel' ? 260 : 90, variant === 'panel' ? 950 : 520);
        this.active = false;
        this.alpha = random(0.42, 0.88);
      }

      update() {
        if (reduceMotion) return;
        if (!this.active) {
          this.wait -= 1;
          if (this.wait <= 0) this.active = true;
          return;
        }

        this.x -= this.speed;
        this.y += this.speed * (variant === 'panel' ? 0.44 : 0.58);
        if (this.x < -this.length || this.y > height + this.length) this.reset();
      }

      draw() {
        if (!this.active || reduceMotion) return;
        const slope = variant === 'panel' ? 0.44 : 0.58;
        const tailX = this.x + this.length;
        const tailY = this.y - this.length * slope;
        const gradient = ctx.createLinearGradient(this.x, this.y, tailX, tailY);
        gradient.addColorStop(0, `rgba(255, 255, 255, ${this.alpha})`);
        gradient.addColorStop(0.18, `rgba(121, 232, 255, ${this.alpha * 0.40})`);
        gradient.addColorStop(1, 'rgba(121, 232, 255, 0)');
        ctx.strokeStyle = gradient;
        ctx.lineWidth = 1.4;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(this.x, this.y);
        ctx.lineTo(tailX, tailY);
        ctx.stroke();
      }
    }

    function resize() {
      const ratio = Math.min(window.devicePixelRatio || 1, 2);
      width = window.innerWidth;
      height = window.innerHeight;
      canvas.width = Math.floor(width * ratio);
      canvas.height = Math.floor(height * ratio);
      ctx.setTransform(ratio, 0, 0, ratio, 0, 0);

      const divisor = variant === 'panel' ? 12500 : 13000;
      const count = Math.min(190, Math.max(68, Math.floor((width * height) / divisor)));
      stars = Array.from({ length: count }, () => new Star());
      streaks = Array.from({ length: width > 760 ? 3 : 1 }, () => new Streak());
    }

    function drawAtmosphere() {
      if (variant !== 'panel') return;
      const blue = ctx.createRadialGradient(width * 0.18, height * 0.16, 0, width * 0.18, height * 0.16, width * 0.46);
      blue.addColorStop(0, 'rgba(121, 232, 255, 0.075)');
      blue.addColorStop(1, 'rgba(121, 232, 255, 0)');
      ctx.fillStyle = blue;
      ctx.fillRect(0, 0, width, height);

      const violet = ctx.createRadialGradient(width * 0.82, height * 0.82, 0, width * 0.82, height * 0.82, width * 0.40);
      violet.addColorStop(0, 'rgba(168, 139, 255, 0.07)');
      violet.addColorStop(1, 'rgba(168, 139, 255, 0)');
      ctx.fillStyle = violet;
      ctx.fillRect(0, 0, width, height);
    }

    function drawConstellations() {
      const maxDistance = Math.min(154, Math.max(88, width / 9));
      const opacityBase = variant === 'panel' ? 0.13 : 0.16;
      for (let i = 0; i < stars.length; i += 1) {
        for (let j = i + 1; j < stars.length; j += 1) {
          const dx = stars[i].x - stars[j].x;
          const dy = stars[i].y - stars[j].y;
          const distance = Math.hypot(dx, dy);
          if (distance < maxDistance) {
            const opacity = (1 - distance / maxDistance) * opacityBase;
            ctx.strokeStyle = `rgba(121, 232, 255, ${opacity})`;
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(stars[i].x, stars[i].y);
            ctx.lineTo(stars[j].x, stars[j].y);
            ctx.stroke();
          }
        }
      }
    }

    function render() {
      ctx.clearRect(0, 0, width, height);
      drawAtmosphere();
      stars.forEach((star) => {
        star.update();
        star.draw();
      });
      drawConstellations();
      streaks.forEach((streak) => {
        streak.update();
        streak.draw();
      });
      if (!reduceMotion) rafId = requestAnimationFrame(render);
    }

    function onResize() {
      resize();
      if (reduceMotion) render();
    }

    window.addEventListener('resize', onResize, { passive: true });
    resize();
    render();

    return () => {
      window.removeEventListener('resize', onResize);
      if (rafId) cancelAnimationFrame(rafId);
    };
  }, [variant]);

  return <canvas ref={canvasRef} id={id} className={className} aria-hidden="true" />;
}
