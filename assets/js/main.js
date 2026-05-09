// Main JavaScript file for PhoneStore

// Smooth scroll behavior
document.addEventListener('DOMContentLoaded', function () {
    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
        
    });

    // đặt NGAY TẠI ĐÂY (bên trong DOMContentLoaded ngoài)
const modal = document.getElementById('discountModal');
console.log('discountModal =', modal);

if (modal) {
  const dmCode = document.getElementById('dmCode');
  const dmDiscount = document.getElementById('dmDiscount');
  const dmMin = document.getElementById('dmMin');
  const dmMax = document.getElementById('dmMax');
  const dmRemain = document.getElementById('dmRemain');
  const dmStart = document.getElementById('dmStart');
  const dmEnd = document.getElementById('dmEnd');
  const dmCopyBtn = document.getElementById('dmCopyBtn');
  const dmCopiedHint = document.getElementById('dmCopiedHint');

  let currentCode = '';

  const money = (v) => new Intl.NumberFormat('vi-VN').format(Number(v || 0)) + 'đ';
  const fmtDate = (s) => {
    if (!s) return 'Không có';
    const d = new Date(s.replace(' ', 'T'));
    if (isNaN(d.getTime())) return s;
    return d.toLocaleDateString('vi-VN');
  };

  function openModal() {
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (dmCopiedHint) dmCopiedHint.style.display = 'none';
  }

  // Click chip -> mở modal
  document.addEventListener('click', (e) => {
    const chip = e.target.closest('.ticker-chip[data-code]');
    if (!chip) return;

    console.log('clicked chip', chip.dataset);

    const type = chip.dataset.type || '';
    const value = Number(chip.dataset.value || 0);
    const min = Number(chip.dataset.min || 0);
    const max = Number(chip.dataset.max || 0);
    const qty = Number(chip.dataset.qty || 0);
    const used = Number(chip.dataset.used || 0);
    const start = chip.dataset.start || '';
    const end = chip.dataset.end || '';

    currentCode = chip.dataset.code || '';

    const discountText = (type === 'percent')
      ? `Giảm ${value}%`
      : `Giảm ${money(value)}`;

    const minText = min > 0 ? money(min) : 'Áp dụng mọi đơn';
    const maxText = max > 0 ? money(max) : 'Không giới hạn';
    const remainText = (qty === 0) ? 'Không giới hạn' : String(Math.max(0, qty - used));

    dmCode.textContent = currentCode || '—';
    dmDiscount.textContent = discountText;
    dmMin.textContent = minText;
    dmMax.textContent = maxText;
    dmRemain.textContent = remainText;
    dmStart.textContent = start ? fmtDate(start) : 'Không giới hạn';
    dmEnd.textContent = end ? fmtDate(end) : 'Không hạn';

    openModal();
  });

  // Copy trong modal
  dmCopyBtn?.addEventListener('click', async () => {
    if (!currentCode) return;

    try {
      await navigator.clipboard.writeText(currentCode);
    } catch (err) {
      const ta = document.createElement('textarea');
      ta.value = currentCode;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    }

    dmCopiedHint.style.display = 'block';
    setTimeout(() => (dmCopiedHint.style.display = 'none'), 1200);
  });

  // Đóng modal
  modal.addEventListener('click', (e) => {
    if (e.target.closest('[data-close="1"]')) closeModal();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
  });
}

    // Form validation
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(form => {
        form.addEventListener('submit', function (e) {
            const inputs = form.querySelectorAll('[required]');
            let isValid = true;

            inputs.forEach(input => {
                if (!input.value.trim()) {
                    isValid = false;
                    input.style.borderColor = 'var(--danger-color)';

                    setTimeout(() => {
                        input.style.borderColor = '';
                    }, 3000);
                }
            });

            if (!isValid) {
                e.preventDefault();
                showNotification('Vui lòng điền đầy đủ thông tin', 'danger');
            }
        });
    });

    document.querySelectorAll('form[action="cart-action.php"]').forEach(form => {
    form.addEventListener('submit', function () {
        const btn = this.querySelector('button[type="submit"]');
        if (!btn) return;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <span>Đang thêm...</span>';
        btn.disabled = true;
    });
    });


    // Quantity input validation
    const quantityInputs = document.querySelectorAll('input[type="number"][name="quantity"]');
    quantityInputs.forEach(input => {
        input.addEventListener('change', function () {
            const min = parseInt(this.min) || 1;
            const max = parseInt(this.max) || Infinity;
            let value = parseInt(this.value);

            if (value < min) this.value = min;
            if (value > max) {
                this.value = max;
                showNotification(`Số lượng tối đa là ${max}`, 'warning');
            }
        });
    });

    // Image lazy loading
    const images = document.querySelectorAll('img[data-src]');
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.dataset.src;
                img.removeAttribute('data-src');
                observer.unobserve(img);
            }
        });
    });

    images.forEach(img => imageObserver.observe(img));

    // Search input enhancement
    const searchInput = document.querySelector('.search-form input[type="search"]');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimeout);
            const value = this.value.trim();

            if (value.length > 0) {
                this.style.paddingRight = '90px';
            } else {
                this.style.paddingRight = '50px';
            }
        });
    }

    // Dropdown menu behavior for mobile
    const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
    dropdownToggles.forEach(toggle => {
        toggle.addEventListener('click', function (e) {
            if (window.innerWidth <= 768) {
                e.preventDefault();
                const menu = this.nextElementSibling;
                const isVisible = menu.style.opacity === '1';

                // Close all other dropdowns
                document.querySelectorAll('.dropdown-menu').forEach(m => {
                    m.style.opacity = '0';
                    m.style.visibility = 'hidden';
                });

                if (!isVisible) {
                    menu.style.opacity = '1';
                    menu.style.visibility = 'visible';
                    menu.style.transform = 'translateY(0)';
                }
            }
        });
    });

    // Close dropdowns when clicking outside
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                menu.style.opacity = '0';
                menu.style.visibility = 'hidden';
            });
        }
    });

    // Scroll to top button
    createScrollToTopButton();

    // Card hover effects enhancement
    enhanceCardHoverEffects();

    // Price formatting
    formatPrices();
});

// Show notification as toast (top-right)
function showNotification(message, type = 'info', duration = 3000) {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <span class="toast-msg">${message}</span>
        <button type="button" class="toast-close">×</button>
    `;

    document.body.appendChild(toast);

    // Close manually
    toast.querySelector('.toast-close').addEventListener('click', () => {
        removeToast(toast);
    });

    // Auto hide
    setTimeout(() => removeToast(toast), duration);
}

// Fade out + remove
function removeToast(toast) {
    if (!toast) return;
    toast.style.opacity = '0';
    toast.style.transform = 'translateY(-10px)';
    setTimeout(() => toast.remove(), 300);
}


// Create scroll to top button
function createScrollToTopButton() {
    const scrollBtn = document.createElement('button');
    scrollBtn.innerHTML = '<i class="fas fa-arrow-up"></i>';
    scrollBtn.className = 'scroll-to-top';
    scrollBtn.style.cssText = `
        position: fixed;
        bottom: 30px;
        right: 30px;
        width: 50px;
        height: 50px;
        background: var(--primary-color);
        color: white;
        border: none;
        border-radius: 50%;
        cursor: pointer;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        box-shadow: var(--shadow-lg);
        z-index: 999;
        font-size: 20px;
    `;

    document.body.appendChild(scrollBtn);

    window.addEventListener('scroll', () => {
        if (window.pageYOffset > 300) {
            scrollBtn.style.opacity = '1';
            scrollBtn.style.visibility = 'visible';
        } else {
            scrollBtn.style.opacity = '0';
            scrollBtn.style.visibility = 'hidden';
        }
    });

    scrollBtn.addEventListener('click', () => {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });

    scrollBtn.addEventListener('mouseenter', () => {
        scrollBtn.style.transform = 'scale(1.1)';
        scrollBtn.style.background = 'var(--primary-dark)';
    });

    scrollBtn.addEventListener('mouseleave', () => {
        scrollBtn.style.transform = 'scale(1)';
        scrollBtn.style.background = 'var(--primary-color)';
    });
}

// Enhance card hover effects
function enhanceCardHoverEffects() {
    const cards = document.querySelectorAll('.card, .brand-card, .feature-card');

    cards.forEach(card => {
        card.addEventListener('mouseenter', function () {
            this.style.transition = 'all 0.3s cubic-bezier(0.4, 0, 0.2, 1)';
        });
    });
}

// Format prices
function formatPrices() {
    const priceElements = document.querySelectorAll('[data-price]');

    priceElements.forEach(element => {
        const price = parseInt(element.dataset.price);
        if (!isNaN(price)) {
            element.textContent = new Intl.NumberFormat('vi-VN', {
                style: 'currency',
                currency: 'VND'
            }).format(price);
        }
    });
}

// Debounce function for search
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Handle responsive navigation
function handleResponsiveNav() {
    const navbar = document.querySelector('.navbar');
    let lastScroll = 0;

    window.addEventListener('scroll', () => {
        const currentScroll = window.pageYOffset;

        if (currentScroll > lastScroll && currentScroll > 100) {
            navbar.style.transform = 'translateY(-100%)';
        } else {
            navbar.style.transform = 'translateY(0)';
        }

        lastScroll = currentScroll;
    });
}

// Initialize responsive navigation
handleResponsiveNav();

// Animate elements on scroll
const animateOnScroll = () => {
    const elements = document.querySelectorAll('.card, .brand-card, .feature-card');

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (entry.isIntersecting) {
                setTimeout(() => {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }, index * 50);
                observer.unobserve(entry.target);
            }
        });
    }, {
        threshold: 0.1
    });

    elements.forEach(element => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(20px)';
        observer.observe(element);
    });
};

// Run animation on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', animateOnScroll);
} else {
    animateOnScroll();
}

// Export functions for use in other scripts
window.PhoneStore = {
    showNotification,
    debounce
};

document.addEventListener('submit', (e) => {
  console.log('SUBMIT event fired on:', e.target);
}, true); // capture

document.addEventListener('click', (e) => {
  const btn = e.target.closest('button[type="submit"]');
  if (!btn) return;
  console.log('CLICK submit button:', btn, 'defaultPrevented=', e.defaultPrevented);
}, true);
