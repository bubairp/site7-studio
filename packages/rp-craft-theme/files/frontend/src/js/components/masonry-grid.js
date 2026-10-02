/**
 * Cascading (Masonry) grid layout
 *
 * @requires https://github.com/desandro/imagesloaded
 * @requires https://github.com/Vestride/Shuffle
 */
import imagesLoaded from 'imagesloaded';
import Shuffle from 'shufflejs';

export const initMasonryGrid = () => {
  const grid = document.querySelectorAll('.masonry-grid')
  let masonry

  if (grid === null) return

  for (let i = 0; i < grid.length; i++) {
     
    masonry = new Shuffle(grid[i], {
      itemSelector: '.masonry-grid-item',
      sizer: '.masonry-grid-item',
    })

    imagesLoaded(grid[i]).on('progress', () => {
      masonry.layout()
    })

    const observer = new MutationObserver((mutations) => {
      const addedElements = [];
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === Node.ELEMENT_NODE) {
            if (node.matches('.masonry-grid-item')) {
              addedElements.push(node);
            } else {
              const items = node.querySelectorAll('.masonry-grid-item');
              if (items.length) {
                addedElements.push(...Array.from(items));
              }
            }
          }
        });
      });

      if (addedElements.length) {
        imagesLoaded(addedElements).on('progress', () => {
          masonry.layout();
        });
        masonry.add(addedElements);
      }
    });

    observer.observe(grid[i], { childList: true, subtree: true });
     

    // Filtering
    const filtersWrap = grid[i].closest('.masonry-filterable')
    if (filtersWrap === null) return
    const filters = filtersWrap.querySelectorAll(
      '.masonry-filters [data-group]'
    )

    for (let n = 0; n < filters.length; n++) {
      filters[n].addEventListener('click', function (e) {
        const current = filtersWrap.querySelector('.masonry-filters .active')
        const target = this.dataset.group
        if (current !== null) {
          current.classList.remove('active')
        }
        this.classList.add('active')
        masonry.filter(target)
        e.preventDefault()
      })
    }
  }
};
