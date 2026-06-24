// toast.js
function showToast(message, options = {}) {
    // Default options
    const {
        duration = 2000,
        background = "#72d898", // green by default
        color = "#fff",
        fontSize = "14px"
    } = options;

    // Check if toast container exists, if not, create it
    let container = document.getElementById("toast-container");
    if (!container) {
        container = document.createElement("div");
        container.id = "toast-container";
        container.style.position = "fixed";
        container.style.top = "20px";
        container.style.left = "50%";
        container.style.transform = "translateX(-50%)";
        container.style.zIndex = "9999";
        container.style.display = "flex";
        container.style.flexDirection = "column";
        container.style.alignItems = "center";
        container.style.gap = "10px";
        document.body.appendChild(container);
    }

    // Create toast element
    const toast = document.createElement("div");
    toast.style.background = background;
    toast.style.color = color;
    toast.style.padding = "12px 20px";
    toast.style.borderRadius = "10px";
    toast.style.fontSize = fontSize;
    toast.style.boxShadow = "0 6px 16px rgba(0,0,0,0.15)";
    toast.style.opacity = "0";
    toast.style.transform = "translateY(-30px)";
    toast.style.transition = "all 0.4s ease";
    toast.innerText = message;

    container.appendChild(toast);

    // Animate in (slide down)
    requestAnimationFrame(() => {
        toast.style.opacity = "1";
        toast.style.transform = "translateY(0)";
    });

    // Auto disappear after duration
    setTimeout(() => {
        toast.style.opacity = "0";
        toast.style.transform = "translateY(-30px)";
        setTimeout(() => toast.remove(), 400);
    }, duration);
}
