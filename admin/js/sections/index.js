/* Section registry. Each section: {key, label, icon, role | roles, group, view(main, route, alive)}.
   A section shows in the menu from its minimum role upward; the server enforces the same rule on every endpoint. */
import dashboard from './dashboard.js';
import orders from './orders.js';
import products from './products.js';
import categories from './categories.js';
import media from './media.js';
import translations from './translations.js';
import currencies from './currencies.js';
import website from './website.js';
import payments from './payments.js';
import shipping from './shipping.js';
import staff from './staff.js';
import activity from './activity.js';
import account from './account.js';

export const sections = [dashboard, orders, products, categories, media, translations, website, payments, shipping, currencies, staff, activity, account];
