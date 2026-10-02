/**
 * Global Form Handler
 * Handles validation, AJAX submission, and reCAPTCHA v3.
 */

export const initFormValidation = () => {
  const initForms = () => {
    const selector = 'needs-validation'
    const forms = document.getElementsByClassName(selector)

    Array.from(forms).forEach((form) => {
      form.addEventListener('submit', (e) => {
        // 1. Basic Bootstrap Validation
        if (!form.checkValidity()) {
          e.preventDefault()
          e.stopPropagation()
          form.classList.add('was-validated')
          return
        }

        form.classList.add('was-validated')

        // 2. Clear previous custom errors
        form.querySelectorAll('.wf-error').forEach(el => el.textContent = '')

        // 3. Prevent default to handle reCAPTCHA or AJAX flow
        e.preventDefault()
        handleSubmissionFlow(form)
      }, false)
    })
  }

  const handleSubmissionFlow = (form) => {
    const isAjax = form.dataset.ajax === 'true'
    const siteKey = form.dataset.recaptchaKey
    const submitBtn = form.querySelector('.wf-submit-btn') || form.querySelector('button[type="submit"]')
    const spinner = submitBtn?.querySelector('.spinner-border, .tw-loading')
    const btnText = submitBtn?.querySelector('.button-text')

    const setLoader = (active) => {
      if (!submitBtn) return
      if (active) {
        submitBtn.disabled = true
        spinner?.classList.remove('d-none', 'hidden')
        if (btnText) {
          btnText.dataset.origText = btnText.textContent
          btnText.textContent = form.dataset.loadingText || 'Submitting...'
        }
      } else {
        submitBtn.disabled = false
        if (spinner) {
          if (spinner.classList.contains('tw-loading') || spinner.classList.contains('hidden') !== false) {
             spinner.classList.add('hidden')
          } else {
             spinner.classList.add('hidden')
          }
        }
        if (btnText) btnText.textContent = btnText.dataset.origText || 'Submit'
      }
    }

    const performFinalSubmit = (token = '') => {
      // Append reCAPTCHA token to form data
      if (token) {
        let recaptchaInput = form.querySelector('input[name="g-recaptcha-response"]')
        if (!recaptchaInput) {
          recaptchaInput = document.createElement('input')
          recaptchaInput.type = 'hidden'
          recaptchaInput.name = 'g-recaptcha-response'
          form.appendChild(recaptchaInput)
        }
        recaptchaInput.value = token
      }

      if (isAjax) {
        performAjaxSubmission(form, setLoader)
      } else {
        // For non-ajax, we just let the form submit naturally now that we have the token
        form.submit()
      }
    }

    setLoader(true)

    // Handle reCAPTCHA if configured
    if (typeof grecaptcha !== 'undefined' && siteKey) {
      grecaptcha.ready(() => {
        grecaptcha.execute(siteKey, { action: 'form_submission' }).then((token) => {
          performFinalSubmit(token)
        })
      })
    } else {
      performFinalSubmit()
    }
  }

  const performAjaxSubmission = (form, setLoader) => {
    const formData = new FormData(form)
    const actionUrl = form.getAttribute('action') || window.location.href

    fetch(actionUrl, {
      method: form.getAttribute('method') || 'POST',
      body: formData,
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    .then(async response => {
      const contentType = response.headers.get('content-type')
      if (!response.ok) {
        if (contentType && contentType.indexOf('application/json') !== -1) {
          const errorData = await response.json()
          throw new Error(errorData.message || `Server error: ${response.status}`)
        } else {
          const errorText = await response.text()
          console.error('Server error response:', errorText)
          throw new Error(`Server returned ${response.status}. Please check console for details.`)
        }
      }
      
      if (contentType && contentType.indexOf('application/json') !== -1) {
        return response.json()
      } else {
        const text = await response.text()
        console.error('Received non-JSON response:', text)
        throw new Error('Server did not return a valid JSON response.')
      }
    })
    .then(data => {
      setLoader(false)
      if (data.success) {
        handleSuccess(form, data)
      } else {
        handleErrors(form, data)
      }
    })
    .catch(error => {
      setLoader(false)
      console.error('Submission error:', error)
      alert(error.message || 'An unexpected error occurred. Please try again later.')
    })
  }

  const handleSuccess = (form, data) => {
    const wrapperId = form.dataset.successWrapper
    const wrapper = wrapperId ? document.getElementById(wrapperId) : null
    const messageEl = document.getElementById(form.dataset.successMessageId || 'form-response-message')

    if (wrapper) {
      form.classList.add('hidden')
      wrapper.classList.remove('hidden')
      if (messageEl) messageEl.textContent = data.message || 'Success!'
      wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' })
    } else {
      alert(data.message || 'Form submitted successfully!')
      form.reset()
      form.classList.remove('was-validated')
    }
  }

  const handleErrors = (form, data) => {
    if (data.errors) {
      Object.keys(data.errors).forEach(key => {
        const errorEl = form.querySelector(`.wf-error[data-field="${key}"]`)
        if (errorEl) {
          errorEl.textContent = Array.isArray(data.errors[key]) ? data.errors[key].join(', ') : data.errors[key]
        }
      })
    } else {
      alert(data.message || 'An error occurred. Please check the form.')
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initForms)
  } else {
    initForms()
  }
};
