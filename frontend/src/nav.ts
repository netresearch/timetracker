// The main nav lives in the server-rendered shared header
// (templates/partials/header.html.twig) and is identical on both shells.
// Within the SPA, client-side routing changes the active item without a page
// load, so we sync the `is-active`/`aria-current` state by the route's first
// path segment against the links' data-nav attribute.
export function syncNav(pathname: string): void {
  const segment = pathname.replace(/^\/ui\/?/, '').split('/')[0] || 'month'

  // Main-bar links plus the relocated Settings/Help icon actions (but not the
  // worktime badges, which also carry data-nav="month").
  for (const link of document.querySelectorAll<HTMLAnchorElement>('.main-nav-link[data-nav], .header-icon-link[data-nav]')) {
    const isActive = link.dataset.nav === segment
    link.classList.toggle('is-active', isActive)
    if (isActive) {
      link.setAttribute('aria-current', 'page')
    } else {
      link.removeAttribute('aria-current')
    }
  }
}
