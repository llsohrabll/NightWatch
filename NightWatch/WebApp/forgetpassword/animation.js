(function () {
  'use strict';

  const canvas = document.getElementById('star-canvas') || document.getElementById('bg-canvas') || document.getElementById('space-canvas');
  if (!canvas) return;

  const ctx = canvas.getContext('2d');
  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  let width = 0;
  let height = 0;
  let stars = [];
  let meteors = [];
  let rafId = null;

  function rand(min, max) {
    return Math.random() * (max - min) + min;
  }

  class Star {
    constructor() { this.reset(true); }

    reset(anywhere = false) {
      this.x = anywhere ? rand(0, width) : rand(-40, width + 40);
      this.y = anywhere ? rand(0, height) : rand(-40, height + 40);
      this.radius = rand(0.55, 1.9);
      this.vx = rand(-0.10, 0.10);
      this.vy = rand(-0.06, 0.12);
      this.alpha = rand(0.25, 0.95);
      this.phase = rand(0, Math.PI * 2);
      this.twinkle = rand(0.006, 0.018);
      this.hue = Math.random() > 0.78 ? '196, 155, 255' : '125, 231, 255';
    }

    update() {
      this.phase += this.twinkle;
      this.alpha = 0.35 + Math.abs(Math.sin(this.phase)) * 0.55;
      if (!reduceMotion) {
        this.x += this.vx;
        this.y += this.vy;
      }

      if (this.x < -60) this.x = width + 60;
      if (this.x > width + 60) this.x = -60;
      if (this.y < -60) this.y = height + 60;
      if (this.y > height + 60) this.y = -60;
    }

    draw() {
      ctx.beginPath();
      ctx.fillStyle = `rgba(${this.hue}, ${this.alpha})`;
      ctx.shadowBlur = this.radius > 1.4 ? 9 : 0;
      ctx.shadowColor = `rgba(${this.hue}, 0.9)`;
      ctx.arc(this.x, this.y, this.radius, 0, Math.PI * 2);
      ctx.fill();
      ctx.shadowBlur = 0;
    }
  }

  class Meteor {
    constructor() { this.reset(); }

    reset() {
      this.x = rand(width * 0.35, width + 240);
      this.y = rand(-height * 0.35, height * 0.45);
      this.length = rand(90, 190);
      this.speed = rand(8, 16);
      this.wait = rand(90, 520);
      this.active = false;
      this.alpha = rand(0.50, 0.92);
    }

    update() {
      if (reduceMotion) return;
      if (!this.active) {
        this.wait -= 1;
        if (this.wait <= 0) this.active = true;
        return;
      }
      this.x -= this.speed;
      this.y += this.speed * 0.58;
      if (this.x < -this.length || this.y > height + this.length) this.reset();
    }

    draw() {
      if (!this.active || reduceMotion) return;
      const tailX = this.x + this.length;
      const tailY = this.y - this.length * 0.58;
      const gradient = ctx.createLinearGradient(this.x, this.y, tailX, tailY);
      gradient.addColorStop(0, `rgba(255, 255, 255, ${this.alpha})`);
      gradient.addColorStop(0.18, `rgba(125, 231, 255, ${this.alpha * 0.35})`);
      gradient.addColorStop(1, 'rgba(125, 231, 255, 0)');
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
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    width = window.innerWidth;
    height = window.innerHeight;
    canvas.width = Math.floor(width * dpr);
    canvas.height = Math.floor(height * dpr);
    canvas.style.width = `${width}px`;
    canvas.style.height = `${height}px`;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    const count = Math.min(190, Math.max(68, Math.floor((width * height) / 12500)));
    stars = Array.from({ length: count }, () => new Star());
    meteors = Array.from({ length: width > 680 ? 3 : 1 }, () => new Meteor());
  }

  function drawConstellations() {
    const maxDistance = Math.min(154, Math.max(92, width / 9));
    for (let i = 0; i < stars.length; i += 1) {
      for (let j = i + 1; j < stars.length; j += 1) {
        const dx = stars[i].x - stars[j].x;
        const dy = stars[i].y - stars[j].y;
        const dist = Math.hypot(dx, dy);
        if (dist < maxDistance) {
          const opacity = (1 - dist / maxDistance) * 0.18;
          ctx.strokeStyle = `rgba(125, 231, 255, ${opacity})`;
          ctx.lineWidth = 1;
          ctx.beginPath();
          ctx.moveTo(stars[i].x, stars[i].y);
          ctx.lineTo(stars[j].x, stars[j].y);
          ctx.stroke();
        }
      }
    }
  }


  function animate() {
    ctx.clearRect(0, 0, width, height);
    stars.forEach((star) => { star.update(); star.draw(); });
    drawConstellations();
    meteors.forEach((meteor) => { meteor.update(); meteor.draw(); });
    rafId = requestAnimationFrame(animate);
  }

  window.addEventListener('resize', resize, { passive: true });

  resize();
  if (rafId) cancelAnimationFrame(rafId);
  animate();
})();
