// Dashboard counter animation used by the panel's inline data loader.
function animateStatCounter(stat, delay = 0) {
  const target = Number(stat.getAttribute('data-target'));
  if (!Number.isFinite(target) || stat.classList.contains('textual')) return;

  if (stat._animationTimeout) clearTimeout(stat._animationTimeout);

  const start = () => {
    const duration = 820;
    const startedAt = performance.now();

    function frame(now) {
      const progress = Math.min(1, (now - startedAt) / duration);
      const eased = 1 - Math.pow(1 - progress, 3);
      stat.textContent = String(Math.round(target * eased));
      if (progress < 1) {
        stat._animationTimeout = requestAnimationFrame(frame);
      } else {
        stat.textContent = String(target);
      }
    }

    requestAnimationFrame(frame);
  };

  if (delay > 0) {
    stat._animationTimeout = setTimeout(start, delay);
  } else {
    start();
  }
}

(function () {
  'use strict';

  const canvas = document.getElementById('space-canvas');
  if (!canvas) return;

  const ctx = canvas.getContext('2d');
  const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  let width = 0;
  let height = 0;
  let stars = [];
  let comets = [];
  let raf = null;

  const random = (min, max) => Math.random() * (max - min) + min;

  class Star {
    constructor() {
      this.reset(true);
    }

    reset(anywhere = false) {
      this.x = anywhere ? random(0, width) : random(-80, width + 80);
      this.y = anywhere ? random(0, height) : random(-80, height + 80);
      this.baseRadius = random(0.6, 1.9);
      this.radius = this.baseRadius;
      this.vx = random(-0.035, 0.035);
      this.vy = random(-0.02, 0.055);
      this.phase = random(0, Math.PI * 2);
      this.twinkle = random(0.006, 0.018);
      this.alpha = random(0.28, 0.9);
      this.kind = Math.random() > 0.78 ? 'violet' : 'cyan';
    }

    update() {
      this.phase += this.twinkle;
      this.radius = this.baseRadius + Math.sin(this.phase) * 0.22;
      this.alpha = 0.26 + Math.abs(Math.sin(this.phase)) * 0.64;

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

  class Comet {
    constructor() {
      this.reset();
    }

    reset() {
      this.x = random(width * 0.48, width + 360);
      this.y = random(-height * 0.28, height * 0.38);
      this.length = random(130, 260);
      this.speed = random(7, 13);
      this.wait = random(260, 950);
      this.active = false;
      this.alpha = random(0.42, 0.82);
    }

    update() {
      if (reduceMotion) return;
      if (!this.active) {
        this.wait -= 1;
        if (this.wait <= 0) this.active = true;
        return;
      }

      this.x -= this.speed;
      this.y += this.speed * 0.44;

      if (this.x < -this.length || this.y > height + this.length) {
        this.reset();
      }
    }

    draw() {
      if (!this.active || reduceMotion) return;
      const tailX = this.x + this.length;
      const tailY = this.y - this.length * 0.44;
      const gradient = ctx.createLinearGradient(this.x, this.y, tailX, tailY);
      gradient.addColorStop(0, `rgba(255, 255, 255, ${this.alpha})`);
      gradient.addColorStop(0.18, `rgba(121, 232, 255, ${this.alpha * 0.42})`);
      gradient.addColorStop(1, 'rgba(121, 232, 255, 0)');
      ctx.strokeStyle = gradient;
      ctx.lineWidth = 1.5;
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

    const starCount = Math.min(190, Math.max(85, Math.floor((width * height) / 12500)));
    stars = Array.from({ length: starCount }, () => new Star());
    comets = Array.from({ length: width > 800 ? 3 : 1 }, () => new Comet());
  }

  function drawAtmosphere() {
    const blue = ctx.createRadialGradient(width * 0.18, height * 0.16, 0, width * 0.18, height * 0.16, width * 0.46);
    blue.addColorStop(0, 'rgba(121, 232, 255, 0.075)');
    blue.addColorStop(1, 'rgba(121, 232, 255, 0)');
    ctx.fillStyle = blue;
    ctx.fillRect(0, 0, width, height);

    const purple = ctx.createRadialGradient(width * 0.82, height * 0.82, 0, width * 0.82, height * 0.82, width * 0.40);
    purple.addColorStop(0, 'rgba(168, 139, 255, 0.07)');
    purple.addColorStop(1, 'rgba(168, 139, 255, 0)');
    ctx.fillStyle = purple;
    ctx.fillRect(0, 0, width, height);
  }

  function drawConstellations() {
    const maxDistance = Math.min(155, Math.max(88, width / 9));
    for (let i = 0; i < stars.length; i += 1) {
      for (let j = i + 1; j < stars.length; j += 1) {
        const dx = stars[i].x - stars[j].x;
        const dy = stars[i].y - stars[j].y;
        const distance = Math.hypot(dx, dy);
        if (distance < maxDistance) {
          const opacity = (1 - distance / maxDistance) * 0.13;
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
    stars.forEach((star) => { star.update(); star.draw(); });
    drawConstellations();
    comets.forEach((comet) => { comet.update(); comet.draw(); });

    if (!reduceMotion) {
      raf = requestAnimationFrame(render);
    }
  }


  window.addEventListener('resize', () => {
    if (raf) cancelAnimationFrame(raf);
    resize();
    render();
  }, { passive: true });


  resize();
  render();
})();
