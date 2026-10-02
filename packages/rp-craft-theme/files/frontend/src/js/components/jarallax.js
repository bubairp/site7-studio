/**
 * Mouse move jarallax effect
 * @requires https://github.com/nk-o/jarallax
 */
import { jarallax, jarallaxVideo } from "jarallax";
import 'jarallax/dist/jarallax.min.css';

export const initJarallax = () => {
  jarallax(document.querySelectorAll('.jarallax'));
};
