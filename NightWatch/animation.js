(function() {
    "use strict";

    // استفاده از کانواس صحیح: star-canvas
    const canvas = document.getElementById('star-canvas');
    const ctx = canvas.getContext('2d');
    
    let particles = [];
    let shootingStars = [];
    const particleCount = 80;
    const connectionDistance = 150;
    
    let width, height;
    let mouse = { x: -1000, y: -1000 };

    // ======================== کلاس ذره (ستاره پس‌زمینه) ========================
    class Particle {
        constructor() { this.init(); }
        init() {
            this.x = Math.random() * width;
            this.y = Math.random() * height;
            this.size = Math.random() * 1.5 + 0.5;
            this.speedX = (Math.random() - 0.5) * 0.3;
            this.speedY = (Math.random() - 0.5) * 0.3;
            this.opacity = Math.random();
            this.twinkleSpeed = Math.random() * 0.03 + 0.01;
        }
        update() {
            this.x += this.speedX;
            this.y += this.speedY;
            this.opacity += this.twinkleSpeed;
            if (this.opacity > 1 || this.opacity < 0.2) this.twinkleSpeed *= -1;
            
            if (this.x < 0) this.x = width;
            if (this.x > width) this.x = 0;
            if (this.y < 0) this.y = height;
            if (this.y > height) this.y = 0;
        }
        draw() {
            ctx.fillStyle = `rgba(176, 199, 233, ${Math.abs(this.opacity)})`;
            ctx.beginPath();
            ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
            ctx.fill();
        }
    }

    // ======================== کلاس شهاب دنباله‌دار ========================
    class ShootingStar {
        constructor() { this.reset(); }
        reset() {
            this.x = Math.random() * width + width / 2; 
            this.y = -Math.random() * height;
            this.length = Math.random() * 150 + 100;
            this.speed = Math.random() * 20 + 25;
            this.size = Math.random() * 1.5 + 0.5;
            this.waitTime = Math.random() * 300 + 100;
            this.active = false;
            this.angle = Math.PI / 4;
        }
        update() {
            if (this.active) {
                this.x -= Math.cos(this.angle) * this.speed;
                this.y += Math.sin(this.angle) * this.speed;
                if (this.x < -this.length || this.y > height + this.length) {
                    this.reset();
                }
            } else {
                this.waitTime--;
                if (this.waitTime <= 0) this.active = true;
            }
        }
        draw() {
            if (!this.active) return;
            const tailX = this.x + Math.cos(this.angle) * this.length;
            const tailY = this.y - Math.sin(this.angle) * this.length;
            
            const gradient = ctx.createLinearGradient(this.x, this.y, tailX, tailY);
            gradient.addColorStop(0, "rgba(255, 255, 255, 1)");
            gradient.addColorStop(0.1, "rgba(176, 199, 233, 0.4)");
            gradient.addColorStop(1, "rgba(176, 199, 233, 0)");
            
            ctx.beginPath();
            ctx.strokeStyle = gradient;
            ctx.lineWidth = this.size;
            ctx.lineCap = "round";
            ctx.moveTo(this.x, this.y);
            ctx.lineTo(tailX, tailY);
            ctx.stroke();

            ctx.beginPath();
            ctx.fillStyle = "rgba(255, 255, 255, 1)";
            ctx.shadowBlur = 15;
            ctx.shadowColor = "#ffffff";
            ctx.arc(this.x, this.y, this.size * 1.5, 0, Math.PI * 2);
            ctx.fill();
            ctx.shadowBlur = 0;
        }
    }

    // ======================== کلاس ماهواره (آپدیت شده و واقع‌گرایانه) ========================
    class Satellite {
        constructor() {
            this.x = width / 2;
            this.y = height / 2;
            this.angle = 0;
            this.radiusX = Math.random() * 200 + 300;
            this.radiusY = Math.random() * 150 + 200;
            this.speed = 0.005;
        }

        update() {
            this.angle += this.speed;
            this.x = (width / 2) + Math.cos(this.angle) * this.radiusX;
            this.y = (height / 2) + Math.sin(this.angle) * this.radiusY;
            this.radiusX += Math.sin(this.angle * 0.5) * 0.2; 
        }

        draw() {
            const time = Date.now() * 0.005; 
            
            ctx.save();
            
            // انیمیشن شناوری (معلق بودن در فضا)
            const floatOffsetY = Math.sin(time * 0.5) * 4;
            const wobbleAngle = Math.sin(time * 0.3) * 0.05;
            
            ctx.translate(this.x, this.y + floatOffsetY);
            ctx.rotate(this.angle + Math.PI / 2 + wobbleAngle);

            // --- 1. بازوهای نگه‌دارنده پنل‌های خورشیدی ---
            ctx.fillStyle = "#4a5568";
            ctx.fillRect(-35, -2, 70, 4);

            // --- 2. پنل‌های خورشیدی (با شبکه‌بندی) ---
            const drawSolarPanel = (px, py, w, h) => {
                // گرادیان برای شیشه پنل
                let panelGrad = ctx.createLinearGradient(px, py, px, py + h);
                panelGrad.addColorStop(0, "#1a365d");
                panelGrad.addColorStop(0.5, "#2b6cb0");
                panelGrad.addColorStop(1, "#1a365d");
                
                ctx.fillStyle = panelGrad;
                ctx.fillRect(px, py, w, h);
                
                // خطوط شبکه روی پنل
                ctx.strokeStyle = "#63b3ed";
                ctx.lineWidth = 0.5;
                ctx.strokeRect(px, py, w, h);
                ctx.beginPath();
                // خطوط عمودی
                for(let i = 1; i <= 3; i++) {
                    ctx.moveTo(px + (w/4)*i, py);
                    ctx.lineTo(px + (w/4)*i, py + h);
                }
                // خط افقی وسط
                ctx.moveTo(px, py + h/2);
                ctx.lineTo(px + w, py + h/2);
                ctx.stroke();
            };
            
            drawSolarPanel(-45, -8, 25, 16); // پنل چپ
            drawSolarPanel(20, -8, 25, 16);  // پنل راست

            // --- 3. بدنه اصلی ماهواره (استوانه/کپسول) ---
            let bodyGrad = ctx.createLinearGradient(-10, -15, 10, 15);
            bodyGrad.addColorStop(0, "#e2e8f0"); // نقره‌ای روشن
            bodyGrad.addColorStop(0.5, "#a0aec0"); // خاکستری متوسط
            bodyGrad.addColorStop(1, "#4a5568"); // تیره
            
            ctx.fillStyle = bodyGrad;
            // رسم بدنه (در صورت پشتیبانی مرورگر از roundRect بهتر می‌شود، اما برای اطمینان از fillRect استفاده شده)
            ctx.fillRect(-9, -12, 18, 24);
            
            // خطوط روی بدنه برای جزئیات
            ctx.strokeStyle = "#2d3748";
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(-9, -5); ctx.lineTo(9, -5);
            ctx.moveTo(-9, 5); ctx.lineTo(9, 5);
            ctx.stroke();

            // فویل طلایی در انتهای بدنه (محافظ حرارتی)
            ctx.fillStyle = "#d69e2e";
            ctx.fillRect(-8, 12, 16, 5);
            // خطوط ریز روی فویل
            ctx.strokeStyle = "#b7791f";
            ctx.beginPath();
            ctx.moveTo(-6, 12); ctx.lineTo(-6, 17);
            ctx.moveTo(0, 12); ctx.lineTo(0, 17);
            ctx.moveTo(6, 12); ctx.lineTo(6, 17);
            ctx.stroke();

            // --- 4. دیش مخابراتی (آنتن سهموی در بالا) ---
            ctx.beginPath();
            ctx.strokeStyle = "#cbd5e0";
            ctx.lineWidth = 2.5;
            ctx.arc(0, -12, 8, Math.PI, 0); // کاسه دیش
            ctx.stroke();
            
            // میله گیرنده وسط دیش
            ctx.beginPath();
            ctx.moveTo(0, -12);
            ctx.lineTo(0, -20);
            ctx.lineWidth = 1.5;
            ctx.stroke();

            // --- 5. انیمیشن امواج سیگنال از آنتن ---
            const waveTime = (Date.now() * 0.003) % (Math.PI * 2); // چرخه موج
            if (waveTime < Math.PI) {
                ctx.beginPath();
                ctx.strokeStyle = `rgba(100, 200, 255, ${1 - waveTime/Math.PI})`; // محو شدن موج
                ctx.lineWidth = 1.5;
                ctx.arc(0, -20, 4 + waveTime * 4, Math.PI * 1.2, Math.PI * 1.8);
                ctx.stroke();
            }

            // --- 6. 💡 چراغ قرمز چشمک‌زن روی نوک آنتن ---
            const intensity = (Math.sin(time * 2) + 1) / 2 * 0.9 + 0.1;
            const pulseScale = 1 + (Math.sin(time * 2) + 1) / 2 * 0.3;
            const baseRadius = 2.2;
            const currentRadius = baseRadius * pulseScale;
            const lightY = -21;

            // افکت هاله نوری (Glow)
            ctx.shadowBlur = 15 * intensity * pulseScale; 
            ctx.shadowColor = `rgba(255, 0, 0, ${intensity})`;

            // رسم چراغ
            ctx.beginPath();
            ctx.fillStyle = `rgba(255, 51, 51, ${intensity})`; 
            ctx.arc(0, lightY, currentRadius, 0, Math.PI * 2);
            ctx.fill();

            // نقطه مرکزی ثابت و روشن‌تر برای هسته چراغ
            ctx.shadowBlur = 0;
            ctx.beginPath();
            ctx.fillStyle = `rgba(255, 200, 200, ${intensity * 0.8})`; 
            ctx.arc(0, lightY, 1, 0, Math.PI * 2);
            ctx.fill();

            ctx.restore();
        }
    }

    // ======================== رسم اتصالات پویا بین ذرات ========================
    function drawDynamicLines() {
        ctx.lineWidth = 0.5;
        for (let i = 0; i < particles.length; i++) {
            for (let j = i + 1; j < particles.length; j++) {
                const dist = Math.hypot(particles[i].x - particles[j].x, particles[i].y - particles[j].y);
                if (dist < connectionDistance) {
                    ctx.strokeStyle = `rgba(176, 199, 233, ${0.15 * (1 - dist/connectionDistance)})`;
                    ctx.beginPath();
                    ctx.moveTo(particles[i].x, particles[i].y);
                    ctx.lineTo(particles[j].x, particles[j].y);
                    ctx.stroke();
                }
            }
        }
    }

    // ======================== مدیریت اندازه و راه‌اندازی ========================
    function resize() {
        width = canvas.width = window.innerWidth;
        height = canvas.height = window.innerHeight;
        if (particles.length > 0) {
            particles.forEach(p => p.init());
        }
    }

    function initParticles() {
        particles = [];
        shootingStars = [];
        for (let i = 0; i < particleCount; i++) {
            particles.push(new Particle());
        }
        for (let i = 0; i < 2; i++) {
            shootingStars.push(new ShootingStar());
        }
    }

    let satellite;

    function startAnimation() {
        resize();
        initParticles();
        satellite = new Satellite();
        animate();
    }

    function animate() {
        ctx.clearRect(0, 0, width, height);
        
        drawDynamicLines();
        
        particles.forEach(p => {
            p.update();
            p.draw();
        });
        
        shootingStars.forEach(s => {
            s.update();
            s.draw();
        });
        
        satellite.update();
        satellite.draw();

        requestAnimationFrame(animate);
    }

    window.addEventListener('resize', resize);
    window.addEventListener('mousemove', (e) => {
        mouse.x = e.clientX;
        mouse.y = e.clientY;
    });

    // اجرا پس از بارگذاری DOM
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startAnimation);
    } else {
        startAnimation();
    }
})();