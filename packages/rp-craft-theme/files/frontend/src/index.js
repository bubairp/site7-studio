/**
 * Import here any npm modules and your own js/scss
 * You can import npm modules as css, scss or js
 * By importing scss you give yourself the ability to override the variables through resources.scss
 */

/**************
 * Javascript
 **************/

//Npm Libraries
// import 'bootstrap';
import 'lazysizes';

//App
import initApp from './js/app';
import Payments from './js/payments';

document.addEventListener('DOMContentLoaded', () => {
    initApp();
    window.Payments = new Payments();
});

/**************
 * CSS
 **************/
import "./css/app.css";