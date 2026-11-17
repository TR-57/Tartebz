<?php

namespace App\Support;

/**
 * Every email the shop sends: when, to whom, which {variables} it understands, whether it can be switched off,
 * and its default words in English and Arabic. Staff edit the words in Admin › Emails (plain text with simple markup –
 * see EmailMarkup); only what they change is saved (setting "emails.templates"), so "Reset to default" just forgets it.
 */
class EmailTemplates
{
    public const GROUPS = ['orders' => 'Orders', 'carts' => 'Abandoned carts', 'marketing' => 'Marketing', 'store' => 'Store alerts'];

    /** key => [group, label, when, to, vars, optional, kind] */
    public static function all(): array
    {
        $o = ['name', 'order_number', 'order_total', 'items', 'invoice_link', 'store_phone'];

        return [
            // ---- Orders (to the customer) ----
            'order_received' => ['orders', 'Order received (not paid online)', 'A customer orders on WhatsApp – they pay outside the website.', 'Customer',
                array_merge($o, ['pay_methods', 'payment_note']), false],
            'order_paid' => ['orders', 'Order confirmation + invoice', 'The order is paid: by card (Tap notice or the return page) or marked paid by staff. Sent once.', 'Customer',
                array_merge($o, ['payment_note', 'delivery']), false],
            'payment_reminder' => ['orders', 'Payment reminder', 'Minutes after an unpaid card order (set below), once, while its items are still held for the customer.', 'Customer',
                array_merge($o, ['amount_due', 'held_until']), true],
            'payment_link' => ['orders', 'Payment link', 'Staff send a payment link from the order (retry, changed amount, a staff order).', 'Customer',
                array_merge($o, ['amount_due', 'pay_methods']), false],
            'status_processing' => ['orders', 'Status: preparing', 'Staff move the order to Preparing (with “Email the customer” ticked).', 'Customer', $o, true],
            'status_shipped' => ['orders', 'Status: shipped', 'The order is shipped (by staff, or SMSA picked it up).', 'Customer',
                array_merge($o, ['tracking_number', 'tracking_link']), false],
            'status_ready' => ['orders', 'Status: ready to collect', 'A collect-from-the-shop order is ready.', 'Customer', array_merge($o, ['store_address']), false],
            'status_delivered' => ['orders', 'Status: delivered', 'The order is delivered (by staff, or SMSA’s tracking says delivered).', 'Customer', $o, true],
            'status_cancelled' => ['orders', 'Status: cancelled', 'Staff cancel the order (with “Email the customer” ticked).', 'Customer', array_merge($o, ['reason']), false],
            'status_refunded' => ['orders', 'Refund', 'A refund is confirmed (full or part).', 'Customer', array_merge($o, ['refund_amount', 'refund_kind']), false],
            'tracking_number' => ['orders', 'Tracking number', 'The moment the SMSA waybill and tracking number are created.', 'Customer',
                array_merge($o, ['tracking_number', 'tracking_link']), false],
            'review_request' => ['orders', 'Review request', 'Days after the order ships or is delivered (set below), once per paid order.', 'Customer',
                ['name', 'order_number', 'review_items'], true],
            'back_in_stock' => ['orders', 'Back in stock', 'A product someone asked about is back in stock.', 'Customer who asked', ['name', 'product', 'product_link'], true],

            // ---- Abandoned carts (only to people who did not unsubscribe) ----
            'cart_1' => ['carts', 'Cart email 1 – reminder', 'The first wait after a cart is left with an email address.', 'Customer', ['name', 'items'], true, 'recovery'],
            'cart_2' => ['carts', 'Cart email 2 – with a code', 'The second wait, when coupons are on and the customer had no recent code.', 'Customer',
                ['name', 'items', 'code', 'code_box', 'offer', 'ends', 'valid_for'], true, 'recovery'],
            'cart_2_nocode' => ['carts', 'Cart email 2 – no code', 'The second wait, when coupons are off or the customer had a code recently.', 'Customer', ['name', 'items'], true, 'recovery'],
            'cart_3' => ['carts', 'Cart email 3 – your code ends soon', 'The third wait, while their code is unused.', 'Customer',
                ['name', 'items', 'code', 'code_box', 'offer', 'ends', 'days_left'], true, 'recovery'],
            'cart_3_nocode' => ['carts', 'Cart email 3 – last reminder', 'The third wait, when there is no unused code.', 'Customer', ['name', 'items'], true, 'recovery'],
            'unpaid_1' => ['carts', 'Unpaid order email 1', 'An unpaid card order the system gave up on – first wait.', 'Customer', ['name', 'order_number', 'items'], true, 'recovery'],
            'unpaid_2' => ['carts', 'Unpaid order email 2 – with a code', 'Unpaid order – second wait, with a code.', 'Customer',
                ['name', 'order_number', 'items', 'code', 'code_box', 'offer', 'ends', 'valid_for'], true, 'recovery'],
            'unpaid_2_nocode' => ['carts', 'Unpaid order email 2 – no code', 'Unpaid order – second wait, no code.', 'Customer', ['name', 'order_number', 'items'], true, 'recovery'],
            'unpaid_3' => ['carts', 'Unpaid order email 3 – code ends soon', 'Unpaid order – third wait, while the code is unused.', 'Customer',
                ['name', 'order_number', 'items', 'code', 'code_box', 'offer', 'ends', 'days_left'], true, 'recovery'],
            'unpaid_3_nocode' => ['carts', 'Unpaid order email 3 – last reminder', 'Unpaid order – third wait, no code.', 'Customer', ['name', 'order_number', 'items'], true, 'recovery'],

            // ---- Marketing ----
            'campaign' => ['marketing', 'Campaign', 'Written and sent in Admin › Campaigns.', 'Chosen audience (never unsubscribed addresses)',
                ['name'], false, 'marketing'],
            'popup_code' => ['marketing', 'Pop-up code', 'Someone leaves their email in the pop-up (one code per address).', 'Customer',
                ['name', 'code', 'code_box', 'offer', 'ends', 'valid_for'], false, 'recovery'],

            // ---- Store alerts (to the team) ----
            'staff_new_order' => ['store', 'New order', 'An order is paid, or a WhatsApp order arrives. Reply-To is the customer.', 'Store notification addresses + owners and managers who chose order emails',
                ['order_number', 'name', 'customer', 'items', 'order_total', 'payment_note', 'delivery', 'admin_link'], false, 'staff'],
            'staff_order_update' => ['store', 'Order update', 'An order is paid, changes status, is cancelled or refunded – not to the person who did it.', 'Owners who chose order emails',
                ['order_number', 'change', 'who', 'admin_link'], true, 'staff'],
            'staff_reviews' => ['store', 'Reviews waiting', 'Once a day when new reviews wait for approval.', 'Store notification addresses + owners',
                ['count', 'review_list', 'admin_link'], true, 'staff'],
        ];
    }

    public static function meta(string $key): array
    {
        $m = self::all()[$key] ?? throw new \InvalidArgumentException("Unknown email {$key}");

        return ['key' => $key, 'group' => $m[0], 'label' => $m[1], 'when' => $m[2], 'to' => $m[3], 'vars' => $m[4],
            'optional' => (bool) $m[5], 'kind' => $m[6] ?? 'transactional'];
    }

    public static function enabled(string $key): bool
    {
        return ! in_array($key, (array) (Settings::get('emails')['off'] ?? []), true) || ! self::meta($key)['optional'];
    }

    /** {subject, body} for a language – staff's words if saved, else the default. */
    public static function get(string $key, string $lang): array
    {
        $saved = Settings::get('emails')['templates'][$key][$lang] ?? null;
        $def = self::defaults()[$key][$lang];

        return [
            'subject' => is_array($saved) && trim((string) ($saved['subject'] ?? '')) !== '' ? (string) $saved['subject'] : $def['subject'],
            'body' => is_array($saved) && trim((string) ($saved['body'] ?? '')) !== '' ? (string) $saved['body'] : $def['body'],
            'custom' => is_array($saved),
        ];
    }

    /** Default words. Arabic is written for Gulf customers. */
    public static function defaults(): array
    {
        return [
            'order_received' => [
                'en' => ['subject' => 'We’ve got your order {order_number}', 'body' => "Hi {name},\n\nThank you for your order **{order_number}**. It isn’t paid yet – here is how to pay:\n\n{payment_note}\n\n{items}\n\n{button: View your order}"],
                'ar' => ['subject' => 'استلمنا طلبك رقم {order_number}', 'body' => "هلا {name}،\n\nشكرًا على طلبك رقم **{order_number}**. الطلب لم يُدفع بعد – طريقة الدفع:\n\n{payment_note}\n\n{items}\n\n{button: عرض الطلب}"],
            ],
            'order_paid' => [
                'en' => ['subject' => 'Thank you! Order {order_number} is confirmed', 'body' => "Thank you, {name}!\n\nWe’ve received your order **{order_number}** and your payment. We’re preparing it now and will email you the moment it’s on its way.\n\n{items}\n\n**Payment:** {payment_note}\n**Delivery:** {delivery}\n\n{button: View your order}\n[button: View invoice | {invoice_link}]"],
                'ar' => ['subject' => 'شكرًا لك! تم تأكيد طلبك رقم {order_number}', 'body' => "شكرًا {name}!\n\nاستلمنا طلبك رقم **{order_number}** وتم الدفع بنجاح. بنبدأ نجهّزه الحين، ونرسل لك أول ما يطلع للتوصيل.\n\n{items}\n\n**الدفع:** {payment_note}\n**التوصيل:** {delivery}\n\n{button: عرض الطلب}\n[button: عرض الفاتورة | {invoice_link}]"],
            ],
            'payment_reminder' => [
                'en' => ['subject' => 'Your order {order_number} is waiting for payment', 'body' => "Hi {name},\n\nYour order **{order_number}** isn’t paid yet. Your items are held for you until **{held_until}** – after that they go back on sale.\n\n{items}\n\n{button: Pay {amount_due} now}"],
                'ar' => ['subject' => 'طلبك رقم {order_number} بانتظار الدفع', 'body' => "هلا {name}،\n\nطلبك رقم **{order_number}** لم يُدفع بعد. محجوز لك لين **{held_until}** – بعدها يرجع للبيع.\n\n{items}\n\n{button: ادفع {amount_due} الحين}"],
            ],
            'payment_link' => [
                'en' => ['subject' => 'Pay for your order {order_number}', 'body' => "Hi {name},\n\nHere is the payment link for your order **{order_number}**. Amount to pay: **{amount_due}**.\n\n{items}\n\nYou can pay with {pay_methods}.\n\n{button: Pay now}\n[button: View invoice | {invoice_link}]"],
                'ar' => ['subject' => 'رابط الدفع لطلبك رقم {order_number}', 'body' => "هلا {name}،\n\nهذا رابط الدفع لطلبك رقم **{order_number}**. المبلغ المطلوب: **{amount_due}**.\n\n{items}\n\nتقدر تدفع بـ {pay_methods}.\n\n{button: ادفع الحين}\n[button: عرض الفاتورة | {invoice_link}]"],
            ],
            'status_processing' => [
                'en' => ['subject' => 'We’re preparing your order {order_number}', 'body' => "Hi {name},\n\nGood news – we’ve started preparing your order **{order_number}**. We’ll let you know when it’s on its way.\n\n{button: View your order}"],
                'ar' => ['subject' => 'بدأنا نجهّز طلبك رقم {order_number}', 'body' => "هلا {name}،\n\nخبر حلو – بدأنا نجهّز طلبك رقم **{order_number}**. نعلمك أول ما يطلع للتوصيل.\n\n{button: عرض الطلب}"],
            ],
            'status_shipped' => [
                'en' => ['subject' => 'Your order {order_number} is on its way 🚚', 'body' => "Hi {name},\n\nYour order **{order_number}** is on its way!\n\nTracking number: **{tracking_number}**\n\n{button: Track your order}"],
                'ar' => ['subject' => 'طلبك رقم {order_number} في الطريق 🚚', 'body' => "هلا {name}،\n\nطلبك رقم **{order_number}** في الطريق لك!\n\nرقم التتبّع: **{tracking_number}**\n\n{button: تتبّع الطلب}"],
            ],
            'status_ready' => [
                'en' => ['subject' => 'Your order {order_number} is ready to collect', 'body' => "Hi {name},\n\nYour order **{order_number}** is ready – come and collect it whenever suits you.\n\n**Where:** {store_address}\n**Call us:** {store_phone}\n\n{button: View your order}"],
                'ar' => ['subject' => 'طلبك رقم {order_number} جاهز للاستلام', 'body' => "هلا {name}،\n\nطلبك رقم **{order_number}** جاهز – تقدر تستلمه في أي وقت يناسبك.\n\n**العنوان:** {store_address}\n**للتواصل:** {store_phone}\n\n{button: عرض الطلب}"],
            ],
            'status_delivered' => [
                'en' => ['subject' => 'Your order {order_number} has been delivered 🎉', 'body' => "Hi {name},\n\nYour order **{order_number}** has been delivered. Enjoy it! If anything isn’t right, just reply to this email.\n\n{button: View your order}"],
                'ar' => ['subject' => 'وصل طلبك رقم {order_number} 🎉', 'body' => "هلا {name}،\n\nوصل طلبك رقم **{order_number}**. بالعافية عليك! وإذا فيه أي ملاحظة، رد على هذه الرسالة.\n\n{button: عرض الطلب}"],
            ],
            'status_cancelled' => [
                'en' => ['subject' => 'Your order {order_number} has been cancelled', 'body' => "Hi {name},\n\nYour order **{order_number}** has been cancelled.\n\n{reason}\n\nIf you paid, we’ll email you as soon as your refund is on its way. Questions? Just reply to this email."],
                'ar' => ['subject' => 'تم إلغاء طلبك رقم {order_number}', 'body' => "هلا {name}،\n\nتم إلغاء طلبك رقم **{order_number}**.\n\n{reason}\n\nإذا كنت دافع، بنرسل لك أول ما نرجع المبلغ. عندك سؤال؟ رد على هذه الرسالة."],
            ],
            'status_refunded' => [
                'en' => ['subject' => 'Refund for your order {order_number}', 'body' => "Hi {name},\n\nWe’ve refunded **{refund_amount}** for your order **{order_number}** ({refund_kind}). It usually shows on your card or account within 5–10 working days.\n\n{button: View your order}"],
                'ar' => ['subject' => 'استرجاع مبلغ طلبك رقم {order_number}', 'body' => "هلا {name}،\n\nرجّعنا لك **{refund_amount}** من طلبك رقم **{order_number}** ({refund_kind}). عادةً يظهر في بطاقتك أو حسابك خلال ٥–١٠ أيام عمل.\n\n{button: عرض الطلب}"],
            ],
            'tracking_number' => [
                'en' => ['subject' => 'Tracking number for your order {order_number}', 'body' => "Hi {name},\n\nYour order **{order_number}** is packed and booked with the courier.\n\nTracking number: **{tracking_number}**\n\nTracking shows once the courier has collected the parcel.\n\n{button: Track your order}"],
                'ar' => ['subject' => 'رقم التتبّع لطلبك رقم {order_number}', 'body' => "هلا {name}،\n\nطلبك رقم **{order_number}** جاهز ومسجّل مع شركة الشحن.\n\nرقم التتبّع: **{tracking_number}**\n\nيظهر التتبّع بعد ما تستلم شركة الشحن الطرد.\n\n{button: تتبّع الطلب}"],
            ],
            'review_request' => [
                'en' => ['subject' => 'How was your Tartebz order?', 'body' => "Hi {name},\n\nWe hope you’re enjoying your order **{order_number}**. How would you rate it? Tap a star – it takes a few seconds and really helps us.\n\n{review_items}"],
                'ar' => ['subject' => 'شو رأيك في طلبك من ترتيبز؟', 'body' => "هلا {name}،\n\nنتمنى إن طلبك رقم **{order_number}** عجبك. كم تعطيه؟ اضغط على النجوم – ثواني بس وتفرق معنا كثير.\n\n{review_items}"],
            ],
            'back_in_stock' => [
                'en' => ['subject' => '{product} is back in stock', 'body' => "Good news – **{product}** is back in stock. You asked us to tell you, so here it is. It goes fast, so grab it while you can.\n\n{button: Shop it now}"],
                'ar' => ['subject' => '{product} رجع متوفر', 'body' => "خبر حلو – **{product}** رجع متوفر. طلبت منا نعلمك، وهذا هو. يخلص بسرعة، فلا يفوتك.\n\n{button: اطلبه الحين}"],
            ],

            'cart_1' => [
                'en' => ['subject' => 'You left something sweet behind 🍬', 'body' => "Hi {name},\n\nYour cart is still waiting for you:\n\n{items}\n\n{button: Return to your cart}"],
                'ar' => ['subject' => 'نسيت شي حلو في السلة 🍬', 'body' => "هلا {name}،\n\nسلتك لسّا تنتظرك:\n\n{items}\n\n{button: ارجع لسلتك}"],
            ],
            'cart_2' => [
                'en' => ['subject' => 'A little something to finish your order: {offer}', 'body' => "Hi {name},\n\nStill thinking about it? Here’s **{offer}** – just for you, valid for {valid_for}.\n\n{code_box}\n\n{items}\n\n{button: Use my code}"],
                'ar' => ['subject' => 'هدية بسيطة تكمّل فيها طلبك: {offer}', 'body' => "هلا {name}،\n\nلسّا تفكّر؟ هذا **{offer}** خاص لك، صالح لمدة {valid_for}.\n\n{code_box}\n\n{items}\n\n{button: استخدم الكود}"],
            ],
            'cart_2_nocode' => [
                'en' => ['subject' => 'Your cart is still here', 'body' => "Hi {name},\n\nWe’ve kept your cart for you – but stock is limited:\n\n{items}\n\n{button: Return to your cart}"],
                'ar' => ['subject' => 'سلتك لسّا موجودة', 'body' => "هلا {name}،\n\nحفظنا لك سلتك – بس الكمية محدودة:\n\n{items}\n\n{button: ارجع لسلتك}"],
            ],
            'cart_3' => [
                'en' => ['subject' => 'Your code ends in {days_left}', 'body' => "Hi {name},\n\nJust a reminder – your code for **{offer}** ends on **{ends}**.\n\n{code_box}\n\n{items}\n\n{button: Use my code}"],
                'ar' => ['subject' => 'كودك ينتهي بعد {days_left}', 'body' => "هلا {name}،\n\nتذكير بسيط – كود **{offer}** ينتهي يوم **{ends}**.\n\n{code_box}\n\n{items}\n\n{button: استخدم الكود}"],
            ],
            'cart_3_nocode' => [
                'en' => ['subject' => 'Last call for your cart', 'body' => "Hi {name},\n\nThis is the last reminder about your cart – after this we’ll let it go.\n\n{items}\n\n{button: Return to your cart}"],
                'ar' => ['subject' => 'آخر تذكير بسلتك', 'body' => "هلا {name}،\n\nهذا آخر تذكير بسلتك – بعدها ما بنزعجك.\n\n{items}\n\n{button: ارجع لسلتك}"],
            ],
            'unpaid_1' => [
                'en' => ['subject' => 'Your order {order_number} wasn’t completed', 'body' => "Hi {name},\n\nYour payment for order **{order_number}** didn’t go through, so the order wasn’t completed. Your items are still in your cart:\n\n{items}\n\n{button: Complete my order}"],
                'ar' => ['subject' => 'طلبك رقم {order_number} ما اكتمل', 'body' => "هلا {name}،\n\nالدفع لطلبك رقم **{order_number}** ما تم، فالطلب ما اكتمل. منتجاتك لسّا في السلة:\n\n{items}\n\n{button: أكمل طلبي}"],
            ],
            'unpaid_2' => [
                'en' => ['subject' => 'Finish your order with {offer}', 'body' => "Hi {name},\n\nYour order **{order_number}** is still waiting. Here’s **{offer}** to finish it – valid for {valid_for}.\n\n{code_box}\n\n{items}\n\n{button: Complete my order}"],
                'ar' => ['subject' => 'أكمل طلبك مع {offer}', 'body' => "هلا {name}،\n\nطلبك رقم **{order_number}** لسّا ينتظرك. هذا **{offer}** عشان تكمله – صالح لمدة {valid_for}.\n\n{code_box}\n\n{items}\n\n{button: أكمل طلبي}"],
            ],
            'unpaid_2_nocode' => [
                'en' => ['subject' => 'Your order {order_number} is still waiting', 'body' => "Hi {name},\n\nYour order **{order_number}** is still waiting to be completed:\n\n{items}\n\n{button: Complete my order}"],
                'ar' => ['subject' => 'طلبك رقم {order_number} لسّا ينتظرك', 'body' => "هلا {name}،\n\nطلبك رقم **{order_number}** لسّا ينتظر تكمله:\n\n{items}\n\n{button: أكمل طلبي}"],
            ],
            'unpaid_3' => [
                'en' => ['subject' => 'Your code ends in {days_left}', 'body' => "Hi {name},\n\nYour code for **{offer}** ends on **{ends}** – complete your order before it goes.\n\n{code_box}\n\n{items}\n\n{button: Complete my order}"],
                'ar' => ['subject' => 'كودك ينتهي بعد {days_left}', 'body' => "هلا {name}،\n\nكود **{offer}** ينتهي يوم **{ends}** – أكمل طلبك قبل لا يروح.\n\n{code_box}\n\n{items}\n\n{button: أكمل طلبي}"],
            ],
            'unpaid_3_nocode' => [
                'en' => ['subject' => 'Last reminder about your order', 'body' => "Hi {name},\n\nThis is the last reminder about your order **{order_number}**.\n\n{items}\n\n{button: Complete my order}"],
                'ar' => ['subject' => 'آخر تذكير بطلبك', 'body' => "هلا {name}،\n\nهذا آخر تذكير بطلبك رقم **{order_number}**.\n\n{items}\n\n{button: أكمل طلبي}"],
            ],

            'campaign' => [
                'en' => ['subject' => 'News from Tartebz', 'body' => "Hi {name},\n\n…"],
                'ar' => ['subject' => 'جديد ترتيبز', 'body' => "هلا {name}،\n\n…"],
            ],
            'popup_code' => [
                'en' => ['subject' => 'Your code: {offer} 🎁', 'body' => "Hi{name},\n\nThank you for joining us! Here is your code for **{offer}**, valid until **{ends}**:\n\n{code_box}\n\nAdd it at checkout.\n\n{button: Shop now}"],
                'ar' => ['subject' => 'كودك: {offer} 🎁', 'body' => "هلا{name}،\n\nشكرًا لانضمامك! هذا كودك لـ **{offer}**، صالح لين **{ends}**:\n\n{code_box}\n\nأضفه عند الدفع.\n\n{button: تسوّق الحين}"],
            ],

            'staff_new_order' => [
                'en' => ['subject' => 'New order {order_number} – {order_total}', 'body' => "New order **{order_number}**\n\n**Customer:** {customer}\n**Payment:** {payment_note}\n**Delivery:** {delivery}\n\n{items}\n\n{button: Open the order}"],
                'ar' => ['subject' => 'طلب جديد {order_number} – {order_total}', 'body' => "طلب جديد **{order_number}**\n\n**العميل:** {customer}\n**الدفع:** {payment_note}\n**التوصيل:** {delivery}\n\n{items}\n\n{button: فتح الطلب}"],
            ],
            'staff_order_update' => [
                'en' => ['subject' => 'Order {order_number}: {change}', 'body' => "Order **{order_number}**: {change}\n\nBy: {who}\n\n{button: Open the order}"],
                'ar' => ['subject' => 'الطلب {order_number}: {change}', 'body' => "الطلب **{order_number}**: {change}\n\nبواسطة: {who}\n\n{button: فتح الطلب}"],
            ],
            'staff_reviews' => [
                'en' => ['subject' => '{count} new reviews waiting for approval', 'body' => "{count} new reviews are waiting for approval:\n\n{review_list}\n\n{button: Review them}"],
                'ar' => ['subject' => '{count} تقييمات جديدة بانتظار الموافقة', 'body' => "فيه {count} تقييمات جديدة بانتظار الموافقة:\n\n{review_list}\n\n{button: راجعها}"],
            ],
        ];
    }

    /** Saves staff's words for one email (both languages). Empty = back to the default. */
    public static function save(string $key, array $langs): void
    {
        self::meta($key);
        $e = Settings::get('emails');
        $t = (array) ($e['templates'] ?? []);
        foreach (['en', 'ar'] as $l) {
            $s = mb_substr(trim((string) ($langs[$l]['subject'] ?? '')), 0, 200);
            $b = mb_substr(str_replace("\r\n", "\n", (string) ($langs[$l]['body'] ?? '')), 0, 8000);
            $def = self::defaults()[$key][$l];
            if (($s === '' || $s === $def['subject']) && (trim($b) === '' || trim($b) === trim($def['body']))) {
                unset($t[$key][$l]);
            } else {
                $t[$key][$l] = ['subject' => $s, 'body' => $b];
            }
        }
        if (empty($t[$key])) {
            unset($t[$key]);
        }
        $e['templates'] = $t;
        Settings::set('emails', $e);
    }

    public static function reset(string $key): void
    {
        $e = Settings::get('emails');
        unset($e['templates'][$key]);
        Settings::set('emails', $e);
    }

    public static function setEnabled(string $key, bool $on): void
    {
        if (! self::meta($key)['optional']) {
            return;
        }
        $e = Settings::get('emails');
        $off = array_values(array_diff((array) ($e['off'] ?? []), [$key]));
        if (! $on) {
            $off[] = $key;
        }
        $e['off'] = $off;
        Settings::set('emails', $e);
    }
}
