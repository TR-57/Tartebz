/* Section registry. Each section: {key, label, icon, role | roles, group, view(main, route, alive)}.
   A section shows in the menu from its minimum role upward; the server enforces the same rule on every endpoint. */
import dashboard from './dashboard.js';
import translations from './translations.js';
import staff from './staff.js';
import activity from './activity.js';
import account from './account.js';

export const sections = [dashboard, translations, staff, activity, account];
