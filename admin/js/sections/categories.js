import { api, esc, d, pill, field, toggle, formData, showErrors, busy, toast, toastErr, modal, confirmBox, nf, can, icon, isAr } from '../core.js';
import { pickMedia } from './media.js';

const ICON_LABELS = { '': 'None', star: 'Star', drop: 'Drop', bolt: 'Lightning', mix: 'Mix', gift: 'Gift', heart: 'Heart', leaf: 'Leaf', fire: 'Fire' };

export default {
  key: 'categories', label: 'Categories', icon: 'tag', role: 'staff', group: 'catalogue',
  async view(main, route, alive) {
    const r = await api('categories');
    if (!alive()) return;
    const edit = can('manager');
    const cats = r.categories;
    const name = (c) => (isAr() && c.nameAr ? c.nameAr : c.name);

    main.innerHTML = `
      <div class="phead"><div><h1>Categories</h1><p class="sub">How products are grouped on the website. Best sellers is not a category: tick “Best seller” on the product.</p></div>
        <div class="actions">${edit ? `<button class="btn btn-primary" id="add">${icon('plus')} Add a category</button>` : ''}</div></div>
      <div class="table-wrap"><table class="t cards"><thead><tr><th></th><th>Category</th><th class="num">Products</th><th>Shown</th><th></th></tr></thead><tbody>
      ${cats.length ? cats.map((c, i) => {
        const sib = cats.filter((x) => x.parentId === c.parentId);
        const at = sib.indexOf(c);
        return `<tr>
        <td class="thumb">${c.thumb ? `<img src="${esc(c.thumb)}" alt="">` : `<span class="noimg">${icon('tag')}</span>`}</td>
        <td data-label="Category" style="padding-inline-start:${12 + c.depth * 22}px"><b>${d(name(c))}</b>
          <div class="muted small"><span data-noi18n>${esc(isAr() ? c.name : c.nameAr)} · /${esc(c.slug)}</span></div></td>
        <td data-label="Products" class="num"><span data-noi18n>${nf(c.liveProducts)} / ${nf(c.products)}</span><div class="muted small">on the website / all</div></td>
        <td data-label="Shown">${c.live ? pill('On the website', 'green') : pill('Hidden', 'hidden')}${c.inMenu ? '' : ' ' + pill('Not in menu')}</td>
        <td class="num">${edit ? `<span class="row-tools">
          ${at > 0 ? `<button class="iconbtn" data-move="${c.id}" data-dir="-1" aria-label="Move up">${icon('up')}</button>` : ''}
          ${at < sib.length - 1 ? `<button class="iconbtn" data-move="${c.id}" data-dir="1" aria-label="Move down">${icon('down')}</button>` : ''}
          <button class="btn btn-sm" data-edit="${c.id}">Edit</button></span>` : ''}</td></tr>`;
      }).join('') : '<tr><td colspan="5"><p class="empty">No categories yet.</p></td></tr>'}
      </tbody></table></div>`;

    const reload = () => this.view(main, route, alive);
    if (!edit) return;
    main.querySelectorAll('[data-move]').forEach((b) => b.addEventListener('click', async () => {
      const c = cats.find((x) => x.id === +b.dataset.move);
      const sib = cats.filter((x) => x.parentId === c.parentId).map((x) => x.id);
      const i = sib.indexOf(c.id);
      const j = i + +b.dataset.dir;
      [sib[i], sib[j]] = [sib[j], sib[i]];
      try { await api('categories/order', { method: 'POST', body: { ids: sib } }); reload(); } catch (e) { toastErr(e); }
    }));

    const open = (c) => {
      const isNew = !c;
      c = c || { name: '', nameAr: '', blurb: '', blurbAr: '', icon: '', parentId: null, enabled: true, inMenu: true, onHome: false, image: '', weightGrams: null, slug: '' };
      let image = c.image;
      const parents = cats.filter((x) => !c.id || (x.id !== c.id));
      modal({
        title: isNew ? 'Add a category' : 'Edit ' + name(c), wide: true,
        body: `<form id="cf" class="form-grid" novalidate>
          ${field('name', 'Name (English)', c.name)}
          ${field('name_ar', 'Name (Arabic)', c.nameAr, { dir: 'rtl' })}
          ${field('blurb', 'Short text (English)', c.blurb, { textarea: true, rows: 2 })}
          ${field('blurb_ar', 'Short text (Arabic)', c.blurbAr, { textarea: true, rows: 2, dir: 'rtl' })}
          ${field('parent_id', 'Inside', c.parentId || '', { options: [['', 'Top level'], ...parents.map((x) => [x.id, '— '.repeat(x.depth) + name(x)])] })}
          ${field('icon', 'Icon on the website', c.icon, { options: r.icons.map((k) => [k, ICON_LABELS[k] || k]) })}
          ${field('weight_grams', 'Weight for delivery (grams)', c.weightGrams == null ? '' : c.weightGrams, { type: 'number', min: 0, step: 1, dir: 'ltr', hint: 'Used by products that have no weight of their own.' })}
          ${!isNew ? field('slug', 'Web address', c.slug, { dir: 'ltr', hint: 'Letters, numbers and dashes. Changing it breaks old links.' }) : '<div></div>'}
          <div class="field full" data-field="image"><span class="field-label">Picture</span>
            <div class="cat-img" id="cimg"></div></div>
          ${toggle('enabled', 'Show on the website', c.enabled, 'Hiding a category hides the categories inside it too.')}
          ${toggle('in_menu', 'Show in the menu', c.inMenu)}
          ${toggle('on_home', 'Show on the homepage', c.onHome)}
        </form>`,
        foot: `${!isNew ? `<button class="btn btn-danger" id="cdel">${icon('trash')} Delete</button>` : ''}<span style="flex:1"></span>
          <button class="btn" data-close>Cancel</button><button class="btn btn-primary" id="csave">Save</button>`,
        onOpen: (el, close) => {
          const box = el.querySelector('#cimg');
          const draw = () => {
            box.innerHTML = (image ? `<img src="${esc(image.replace(/^\/uploads\//, '/img/400/uploads/'))}" alt="">` : '') +
              `<button type="button" class="btn btn-sm" id="cpick">${icon('image')} ${image ? 'Change' : 'Choose a picture'}</button>` +
              (image ? '<button type="button" class="linkbtn" id="crm">Remove</button>' : '');
            box.querySelector('#cpick').addEventListener('click', async () => {
              const [u] = await pickMedia({ multiple: false, kind: 'content', title: 'Choose a picture' });
              if (u) { image = u; draw(); }
            });
            const rm = box.querySelector('#crm');
            if (rm) rm.addEventListener('click', () => { image = ''; draw(); });
          };
          draw();
          el.querySelector('#csave').addEventListener('click', async (e) => {
            const form = el.querySelector('#cf');
            const body = { ...formData(form), image };
            try {
              await busy(e.currentTarget, () => api(isNew ? 'categories' : 'categories/' + c.id, { method: isNew ? 'POST' : 'PUT', body }));
              close(); toast('Saved'); reload();
            } catch (err) { showErrors(form, err); }
          });
          const del = el.querySelector('#cdel');
          if (del) del.addEventListener('click', async () => {
            if (!(await confirmBox('Delete this category?', { ok: 'Delete', danger: true }))) return;
            try { await api('categories/' + c.id, { method: 'DELETE' }); close(); toast('Deleted'); reload(); } catch (err) { toastErr(err); }
          });
        },
      });
    };
    main.querySelector('#add').addEventListener('click', () => open(null));
    main.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => open(cats.find((x) => x.id === +b.dataset.edit))));
  },
};
