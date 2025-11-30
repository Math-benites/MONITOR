(function(){
    const root = document.getElementById('toast-root');
    if(!root) return;
    let queue = [];
    try {
        queue = JSON.parse(root.dataset.toasts || '[]') || [];
    } catch(e) {
        queue = [];
    }
    if(queue.length === 0) return;

    const container = document.createElement('div');
    container.className = 'toast-container';
    document.body.appendChild(container);

    queue.forEach((toast, index) => {
        const item = document.createElement('div');
        item.className = `toast toast--${toast.type || 'info'}`;
        item.innerHTML = toast.message || '';
        container.appendChild(item);
        setTimeout(() => {
            item.classList.add('toast--visible');
        }, index * 150);

        const remove = () => {
            item.classList.remove('toast--visible');
            item.addEventListener('transitionend', () => item.remove(), { once: true });
        };
        const duration = typeof toast.duration === 'number' ? toast.duration : 4000;
        setTimeout(remove, duration);
        item.addEventListener('click', remove);
    });
})();
