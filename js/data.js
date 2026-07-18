// ═══════════════════════════════════════════════════
// ARTICLE & PRODUCT DATA — placeholder content.
// Later, replace this with fetch() calls to your backend API
// (e.g. GET /api/articles?category=politics) returning the same shape.
// ═══════════════════════════════════════════════════

// layout: 'feature' | 'overlay' | 'rows' | 'briefs' | 'quotes'
const CATEGORIES = [
  { id: 'politics', name: 'Politics',      icon: '🏛️', badge: 'badge-politics', ph: 'ph-politics', color: '#e33b4e', layout: 'feature' },
  { id: 'us-news',  name: 'US News',       icon: '🇺🇸', badge: 'badge-usnews',  ph: 'ph-usnews',  color: '#3b82f6', layout: 'overlay' },
  { id: 'world',    name: 'World',         icon: '🌍', badge: 'badge-world',    ph: 'ph-world',   color: '#10b981', layout: 'rows' },
  { id: 'hope',     name: 'Story of Hope', icon: '🕊️', badge: 'badge-hope',     ph: 'ph-hope',    color: '#f472b6', layout: 'feature' },
  { id: 'opinion',  name: 'Opinion',       icon: '✍️', badge: 'badge-opinion',  ph: 'ph-opinion', color: '#a855f7', layout: 'quotes' },
];

const ARTICLES = {
  politics: [
    { title: 'Senate Committee Launches Investigation Into Federal Spending Program', excerpt: 'A bipartisan panel will examine allegations of mismanagement in the multi-billion dollar initiative.', author: 'Sarah Mitchell', time: '35 min ago', icon: '🏛️' },
    { title: 'Governor Signs Controversial Bill Despite Widespread Protests', excerpt: 'The legislation passed along party lines after weeks of heated debate in the state capitol.', author: 'James Carter', time: '1 hour ago', icon: '📜' },
    { title: 'Former Cabinet Official Announces Surprise Presidential Bid', excerpt: 'The announcement shakes up an already crowded primary field months before the first debates.', author: 'Elena Rodriguez', time: '2 hours ago', icon: '🎤' },
  ],
  'us-news': [
    { title: 'Historic Flooding Forces Thousands to Evacuate Across Midwest', excerpt: 'Emergency crews are working around the clock as rivers crest at record levels.', author: 'David Chen', time: '20 min ago', icon: '🌊' },
    { title: 'Major City Announces Sweeping Reform of Public Transit System', excerpt: 'The billion-dollar overhaul promises faster commutes and expanded coverage by 2028.', author: 'Amanda Foster', time: '1 hour ago', icon: '🚇' },
    { title: 'Supreme Court Agrees to Hear Case That Could Redefine Federal Authority', excerpt: 'Legal scholars call it the most consequential case on the docket this term.', author: 'Robert Hayes', time: '3 hours ago', icon: '⚖️' },
  ],
  world: [
    { title: 'European Leaders Convene Emergency Summit on Energy Crisis', excerpt: 'Officials seek a unified response as prices surge across the continent.', author: 'Marie Dubois', time: '45 min ago', icon: '⚡' },
    { title: 'Peace Talks Resume After Six-Month Stalemate', excerpt: 'Diplomats express cautious optimism as both sides return to the negotiating table.', author: 'Ahmed Hassan', time: '2 hours ago', icon: '🕊️' },
    { title: 'Archaeologists Uncover Ancient City Beneath Desert Sands', excerpt: 'The discovery could rewrite the history of an entire civilization.', author: 'Yuki Tanaka', time: '4 hours ago', icon: '🏺' },
  ],
  hope: [
    { title: 'Veteran Completes Cross-Country Walk, Raising Millions for Wounded Heroes', excerpt: 'After 3,000 miles and 14 states, a hero\'s journey ends with a life-changing donation.', author: 'Daniel Park', time: '1 hour ago', icon: '🎗️' },
    { title: 'Entire Town Shows Up to Rebuild Elderly Couple\'s Home in One Weekend', excerpt: 'More than 200 neighbors volunteered after a fire destroyed everything the couple owned.', author: 'Grace Okafor', time: '3 hours ago', icon: '🏠' },
    { title: 'High School Students\' Invention Brings Clean Water to Thousands', excerpt: 'A science-fair project became a real-world solution now used in three countries.', author: 'Samuel Ross', time: '6 hours ago', icon: '💧' },
  ],
  opinion: [
    { title: 'The Quiet Revolution Happening in American Classrooms', excerpt: 'Why the biggest education story of the decade is going largely unreported.', author: 'Dr. Lisa Wong', time: '1 hour ago', icon: '🎓' },
    { title: 'We Need to Talk About the Future of Local News', excerpt: 'As newsrooms shrink, communities lose more than headlines — they lose accountability.', author: 'Mark Stevens', time: '3 hours ago', icon: '📰' },
    { title: 'What the Data Really Says About the Economy', excerpt: 'Beyond the headlines, the numbers tell a more complicated story.', author: 'Paul Nguyen', time: '6 hours ago', icon: '📊' },
  ],
};

// ── SHOP — political / patriot gadgets & accessories ──
const PRODUCTS = [
  { name: 'Patriot Eagle Embroidered Cap',        price: 24.99, icon: '🧢', tag: 'Best Seller' },
  { name: '"We The People" Insulated Steel Mug',  price: 19.99, icon: '☕', tag: 'New' },
  { name: 'Vintage American Flag T-Shirt',        price: 29.99, icon: '👕', tag: null },
  { name: 'Liberty Eagle Lapel Pin Set (3-Pack)', price: 12.99, icon: '🦅', tag: null },
  { name: 'Freedom Paracord Bracelet',            price: 14.99, icon: '⭐', tag: 'New' },
  { name: 'Constitution Pocket Edition + Case',   price: 16.99, icon: '📜', tag: 'Best Seller' },
  { name: 'Patriot LED Tactical Flashlight',      price: 34.99, icon: '🔦', tag: null },
  { name: 'Stars & Stripes Car Decal Pack',       price: 9.99,  icon: '🚗', tag: null },
];
