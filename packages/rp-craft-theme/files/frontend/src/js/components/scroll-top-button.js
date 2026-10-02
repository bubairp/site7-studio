/**
 * Animate scroll to top button in/off view
 */

export const initScrollTopButton = () => {
  const init = () => {
    const button = document.querySelector('.tw-btn-scroll-top')
    const scrollOffset = 450

    if (button == null) return

    const offsetFromTop = parseInt(scrollOffset, 10)
    const progress = button.querySelector('svg circle')
    const length = progress.getTotalLength()

    progress.style.strokeDasharray = length
    progress.style.strokeDashoffset = length

    const showProgress = () => {
      const scrollPercent =
        (document.body.scrollTop + document.documentElement.scrollTop) /
        (document.documentElement.scrollHeight -
          document.documentElement.clientHeight)
      const draw = length * scrollPercent
      progress.style.strokeDashoffset = length - draw
    }

    // Set initial progress on load
    showProgress()

    window.addEventListener('scroll', (e) => {
      if (e.currentTarget.pageYOffset > offsetFromTop) {
        button.classList.add('opacity-100', 'scale-100')
        button.classList.remove('opacity-0', 'scale-0')
      } else {
        button.classList.remove('opacity-100', 'scale-100')
        button.classList.add('opacity-0', 'scale-0')
      }

      showProgress()
    })
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init)
  } else {
    init()
  }
};
