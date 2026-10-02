/**
 * Mouse move parallax effect
 * @requires https://github.com/wagerfield/parallax
 */
import Parallax from 'parallax-js';

export const initParallax = () => {
  const element = document.querySelectorAll('.parallax')

  /* eslint-disable no-unused-vars */
  for (let i = 0; i < element.length; i++) {
    const parallaxInstance = new Parallax(element[i])
  }
  /* eslint-enable no-unused-vars */
};
