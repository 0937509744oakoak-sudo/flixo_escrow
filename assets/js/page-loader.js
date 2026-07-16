(function () {
    const loader = document.getElementById('page-loader');
    if (!loader) return;

    const divs = loader.querySelectorAll('.page-loader-dot');
    const PARTS = divs.length;

    divs.forEach(function (div, i) {
        div.style.background = 'hsl(200, 100%, ' + (i * 2) + '%)';
        div.style.boxShadow =
            '0 0 20px 20px hsla(200, 100%, ' + (i + PARTS / 2) + '%, 0.03)';
    });

    class Animate {
        constructor({ fps }) {
            this.frame = 0;
            this.fpsInterval = 1000 / fps;
            this.then = Date.now();
            this.startTime = this.then;
            this.rotation = 360 * 2;
            this.rotate = 600;
            this.add = 4;
            this.running = true;
            this.tick();
        }

        rotateCircle(cx, cy, x, y, angle) {
            const radians = (Math.PI / 180) * angle;
            const cos = Math.cos(radians);
            const sin = Math.sin(radians);
            const nx = cos * (x - cx) + sin * (y - cy) + cx;
            const ny = cos * (y - cy) - sin * (x - cx) + cy;
            return { x: nx, y: ny };
        }

        applyRotation(index) {
            const offset = this.add * index;
            const rotate =
                this.rotate + offset >= this.rotation
                    ? this.rotate + offset - this.rotation
                    : this.rotate + offset;

            if (Math.floor(rotate / 360) >= 1) {
                const { x, y } = this.rotateCircle(0, 0, -20, 0, rotate % 360);
                divs[index].style.transform = 'translate(' + x + 'px, ' + y + 'px)';
            } else {
                const { x, y } = this.rotateCircle(0, 0, 20, 0, -(rotate % 360));
                divs[index].style.transform =
                    'translate(' + (x - 40) + 'px, ' + y + 'px)';
            }
        }

        animation() {
            const rotate = this.rotate + this.add;
            this.rotate = this.rotation <= rotate ? 0 : rotate;

            for (let i = 0; i < divs.length; i++) {
                this.applyRotation(i);
            }
        }

        tick() {
            if (!this.running) return;

            this.now = Date.now();
            this.elapsed = this.now - this.then;
            if (this.elapsed > this.fpsInterval) {
                this.then = this.now - (this.elapsed % this.fpsInterval);
                this.animation();
            }
            this.rafId = requestAnimationFrame(this.tick.bind(this));
        }

        stop() {
            this.running = false;
            if (this.rafId) cancelAnimationFrame(this.rafId);
        }
    }

    const animator = new Animate({ fps: 60 });
    const minShowMs = 600;
    const startedAt = Date.now();

    function hideLoader() {
        const wait = Math.max(0, minShowMs - (Date.now() - startedAt));
        setTimeout(function () {
            loader.classList.add('is-done');
            document.body.classList.add('page-loaded');
            animator.stop();
            setTimeout(function () {
                if (loader.parentNode) loader.parentNode.removeChild(loader);
            }, 500);
        }, wait);
    }

    if (document.readyState === 'complete') {
        hideLoader();
    } else {
        window.addEventListener('load', hideLoader);
    }
})();
