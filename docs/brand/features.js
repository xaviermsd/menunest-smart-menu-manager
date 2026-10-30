// Feature cards shared by banner.html and social.html.
const MENUNEST_FEATURES = [
	[ 'ti-sitemap', 'Build', 'Add under a parent', 'Pick once, new items land there' ],
	[ 'ti-list-details', 'Build', 'Bulk add a list', 'Paste names, indent to nest' ],
	[ 'ti-square-plus', 'Build', '+ on every item', 'Add sub-items in place' ],
	[ 'ti-search', 'Navigate', 'Search and collapse', 'Find your way in big menus' ],
	[ 'ti-history', 'Protect', 'Auto backups', 'One-click restore and history' ],
	[ 'ti-arrows-exchange', 'Move', 'Import and export', 'Preview every item first' ],
];

document.getElementById( 'grid' ).innerHTML = MENUNEST_FEATURES.map(
	( [ icon, tag, title, text ] ) =>
		`<div class="card"><div class="chip"><i class="ti ${ icon }"></i></div><div class="tag">${ tag.toUpperCase() }</div><h3>${ title }</h3><p>${ text }</p></div>`
).join( '' );
