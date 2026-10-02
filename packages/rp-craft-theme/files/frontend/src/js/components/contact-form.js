/**
 * Contact Form Component
 * Handles AJAX submission, reCAPTCHA v3, and loading states.
 */

export const initContactForm = () => {
  const form = document.getElementById('contact-form');
  const responseWrapper = document.getElementById('form-response-wrapper');
  const responseMessage = document.getElementById('form-response-message');
  
  if (!form) return;

  const submitBtn = form.querySelector('.wf-submit-btn');
  const spinner = submitBtn?.querySelector('.spinner-border');
  const btnText = submitBtn?.querySelector('.button-text');
  const siteKey = form.dataset.recaptchaKey;

  const resetForm = () => {
    form.reset();
    form.classList.remove('was-validated');
    form.querySelectorAll('.wf-error').forEach(el => el.textContent = '');
  };

  const showMessage = (msg, type = 'success') => {
    if (type === 'success') {
      form.classList.add('hidden');
      responseWrapper?.classList.remove('hidden');
      if (responseMessage) responseMessage.textContent = msg;
      responseWrapper?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
      alert(msg);
    }
  };

  const setLoader = (active) => {
    if (!submitBtn) return;
    if (active) {
      submitBtn.disabled = true;
      spinner?.classList.remove('hidden');
      if (btnText) btnText.textContent = 'Submitting...';
    } else {
      submitBtn.disabled = false;
      spinner?.classList.add('hidden');
      if (btnText) btnText.textContent = 'Submit Message';
    }
  };

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    
    // Clear previous state
    form.querySelectorAll('.wf-error').forEach(el => el.textContent = '');

    if (!form.checkValidity()) {
      event.stopPropagation();
      form.classList.add('was-validated');
      return;
    }

    setLoader(true);

    if (typeof grecaptcha !== 'undefined' && siteKey) {
      grecaptcha.ready(function () {
        grecaptcha.execute(siteKey, { action: 'contact' }).then(function (token) {
          submitFormData(token);
        });
      });
    } else {
      submitFormData();
    }
  }, false);

  const submitFormData = (recaptchaToken = '') => {
    const formData = new FormData(form);
    
    if (recaptchaToken) {
      formData.append('g-recaptcha-response', recaptchaToken);
    }

    // CSRF handled via dataset or standard Craft input
    const csrfName = form.dataset.csrfName;
    const csrfToken = form.dataset.csrfToken;
    if (csrfName && csrfToken) {
      formData.append(csrfName, csrfToken);
    }

    fetch('', {
      method: 'POST',
      body: formData,
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    .then(response => response.json())
    .then(data => {
      setLoader(false);
      if (data.success) {
        showMessage(data.message || 'We have received your message and will get back to you shortly.', 'success');
        resetForm();
      } else {
        if (data.errors) {
          Object.keys(data.errors).forEach(key => {
            const errorEl = form.querySelector(`.wf-error[data-field="${key}"]`);
            if (errorEl) {
              errorEl.textContent = data.errors[key].join(', ');
            }
          });
        } else {
          alert(data.message || 'An error occurred. Please try again.');
        }
      }
    })
    .catch(error => {
      setLoader(false);
      console.error('Error:', error);
      alert('An unexpected error occurred. Please try again later.');
    });
  };
};
