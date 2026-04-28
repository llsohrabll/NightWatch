document.addEventListener('DOMContentLoaded', () => {
  "use strict";

  // ======================== 1. Animated Stat Counters ========================
  const statValues = document.querySelectorAll('.stat-value');
  const speed = 200; // Lower is faster

  statValues.forEach(stat => {
    const dataTargetAttr = stat.getAttribute('data-target');
    if (!dataTargetAttr) {
      return;
    }
    const target = Number(dataTargetAttr);
    if (!Number.isFinite(target)) {
      return;
    }

    const updateCount = () => {
      const count = Number(stat.innerText);
      const inc = target / speed;

      if (Number.isFinite(count) && count < target) {
        stat.innerText = Math.ceil(count + inc);
        setTimeout(updateCount, 15);
      } else {
        stat.innerText = target;
      }
    };
    // Add slight delay to match CSS animations
    setTimeout(updateCount, 600); 
  });

  // ======================== 2. Interactive Frost Network Canvas ========================
  const canvas = document.getElementById('frost-canvas');
  const ctx = canvas.getContext('2d');
  
  let particlesArray = [];
  let w, h;
  let mouse = { x: null, y: null, radius: 150 };

  window.addEventListener('mousemove', (e) => {
    mouse.x = e.x;
    mouse.y = e.y;
  });

  window.addEventListener('mouseout', () => {
    mouse.x = null;
    mouse.y = null;
  });

  function init() {
    w = canvas.width = window.innerWidth;
    h = canvas.height = window.innerHeight;
    particlesArray = [];
    let numberOfParticles = (w * h) / 15000; // Adjust density here

    for (let i = 0; i < numberOfParticles; i++) {
      let x = Math.random() * w;
      let y = Math.random() * h;
      let directionX = (Math.random() * 0.4) - 0.2;
      let directionY = (Math.random() * 0.4) - 0.2;
      let size = (Math.random() * 2) + 1;
      particlesArray.push(new Particle(x, y, directionX, directionY, size));
    }
  }

  class Particle {
    constructor(x, y, directionX, directionY, size) {
      this.x = x;
      this.y = y;
      this.directionX = directionX;
      this.directionY = directionY;
      this.size = size;
      this.baseX = this.x;
      this.baseY = this.y;
      this.density = (Math.random() * 30) + 1;
    }

    draw() {
      ctx.beginPath();
      ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
      ctx.fillStyle = 'rgba(176, 199, 233, 0.8)';
      ctx.fill();
    }

    update() {
      // Normal slow movement
      this.x += this.directionX;
      this.y += this.directionY;

      // Bounce off edges
      if (this.x < 0 || this.x > w) this.directionX = -this.directionX;
      if (this.y < 0 || this.y > h) this.directionY = -this.directionY;

      // Mouse collision/repulsion
      let dx = mouse.x - this.x;
      let dy = mouse.y - this.y;
      let distance = Math.sqrt(dx * dx + dy * dy);
      
      if (distance < mouse.radius) {
        let forceDirectionX = dx / distance;
        let forceDirectionY = dy / distance;
        let force = (mouse.radius - distance) / mouse.radius;
        
        let directionX = forceDirectionX * force * this.density * 0.05;
        let directionY = forceDirectionY * force * this.density * 0.05;

        // Push particles away from mouse gently
        this.x -= directionX;
        this.y -= directionY;
      }

      this.draw();
    }
  }

  function connect() {
    let opacityValue = 1;
    for (let a = 0; a < particlesArray.length; a++) {
      for (let b = a; b < particlesArray.length; b++) {
        let distance = ((particlesArray[a].x - particlesArray[b].x) * (particlesArray[a].x - particlesArray[b].x))
                     + ((particlesArray[a].y - particlesArray[b].y) * (particlesArray[a].y - particlesArray[b].y));
                     
        if (distance < (w / 7) * (h / 7)) {
          opacityValue = 1 - (distance / 20000);
          ctx.strokeStyle = `rgba(176, 199, 233, ${opacityValue * 0.2})`; // Light frost lines
          ctx.lineWidth = 1;
          ctx.beginPath();
          ctx.moveTo(particlesArray[a].x, particlesArray[a].y);
          ctx.lineTo(particlesArray[b].x, particlesArray[b].y);
          ctx.stroke();
        }
      }
    }
  }

  function animate() {
    requestAnimationFrame(animate);
    ctx.clearRect(0, 0, w, h);

    for (let i = 0; i < particlesArray.length; i++) {
      particlesArray[i].update();
    }
    connect();
  }

  window.addEventListener('resize', init);
  
  // Start the engine
  init();
  animate();
});
