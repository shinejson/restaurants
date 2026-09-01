<?php
// admin/printing/ticket_renderer.php
// Shared renderer for receipt templates (KOT, BOT, Order Ticket, Payment Receipt).
// Produces a thermal-printer styled HTML ticket using saved template settings.

if (!function_exists('ticket_paper_width_px')) {
    function ticket_paper_width_px($paper_width)
    {
        return $paper_width === '58mm' ? '219px' : '302px';
    }
}

if (!function_exists('render_ticket')) {
    /**
     * Render a sample ticket.
     *
     * @param string $tpl      One of: kot, bot, order, payment
     * @param array  $p        Printing settings (key => value)
     * @param array  $company  Company info (name, address, phone)
     * @param bool   $inline   TRUE = include data-field attributes for live JS preview
     * @return string HTML
     */
    function render_ticket($tpl, $p, $company, $inline = false)
    {
        $f = function ($key) use ($inline) {
            return $inline ? ' data-field="' . $key . '"' : '';
        };
        $on = function ($key) use ($p) {
            return ($p[$tpl . '_' . $key] ?? '1') == '1';
        };
        $paper = ($p[$tpl . '_paper_width'] ?? '80mm') === '58mm' ? '58mm' : '80mm';
        $width = ticket_paper_width_px($paper);

        // Sample data
        $ref      = $tpl === 'kot' ? 'KOT-000123' : ($tpl === 'bot' ? 'BOT-000123' : ($tpl === 'order' ? 'ORD-000123' : 'RCP-000123'));
        $datetime = date('d M Y, H:i');
        $server   = 'Admin';
        $table    = 'A1';
        $room     = 'Room 101';
        $guests   = 2;
        $customer = 'Walk-in Guest';

        $items = [
            ['name' => 'Jollof Rice', 'qty' => 2, 'price' => 45.00, 'request' => 'Extra pepper'],
            ['name' => 'Grilled Chicken', 'qty' => 1, 'price' => 65.00, 'request' => ''],
            ['name' => 'Coke (Bottle)', 'qty' => 2, 'price' => 12.00, 'request' => 'No ice'],
        ];
        $subtotal = 0;
        foreach ($items as $it) {
            $subtotal += $it['price'] * $it['qty'];
        }
        $tax_rate  = 0.15;
        $tax_total = round($subtotal - ($subtotal / (1 + $tax_rate)), 2); // tax inclusive
        $total     = $subtotal;
        $paid      = 200.00;
        $change    = round($paid - $total, 2);
        $method    = 'Cash';

        $is_ticket = in_array($tpl, ['kot', 'bot']);

        ob_start();
        ?>
        <div class="ticket" style="width:<?php echo $width; ?>; max-width:100%; margin:0 auto; background:#fff; color:#111; font-family:'Courier New',Courier,monospace; font-size:12px; line-height:1.45; padding:14px 12px; box-shadow:0 4px 18px rgba(0,0,0,0.12); border-radius:4px;">

            <?php if (($p[$tpl . '_show_logo'] ?? '1') == '1'): ?>
                <div class="t-center" <?php echo $f('show_logo'); ?>>
                    <?php if (!empty($company['logo'])): ?>
                        <img src="<?php echo htmlspecialchars($company['logo']); ?>" alt="Logo"
                            style="max-height:52px; max-width:80%; margin:0 auto 6px; display:block; object-fit:contain;">
                    <?php else: ?>
                        <div style="width:52px;height:52px;margin:0 auto 6px;border-radius:50%;background:#ff6b35;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;">LOGO</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (($p[$tpl . '_show_restaurant'] ?? '1') == '1'): ?>
                <div class="t-center t-bold" style="font-size:15px;" <?php echo $f('show_restaurant'); ?>><?php echo htmlspecialchars($company['name'] ?: 'FOODEXPRESS'); ?></div>
            <?php endif; ?>
            <?php if (($p[$tpl . '_show_address'] ?? '1') == '1'): ?>
                <div class="t-center t-small" <?php echo $f('show_address'); ?>><?php echo htmlspecialchars($company['address'] ?: '123 Restaurant Street, Accra'); ?></div>
            <?php endif; ?>
            <?php if (($p[$tpl . '_show_phone'] ?? '1') == '1'): ?>
                <div class="t-center t-small" <?php echo $f('show_phone'); ?>>Tel: <?php echo htmlspecialchars($company['phone'] ?: '+233 123 456 789'); ?></div>
            <?php endif; ?>

            <div class="t-sep"></div>

            <div class="t-center t-bold" data-field="header_note"><?php echo htmlspecialchars($p[$tpl . '_header_note'] ?? ''); ?></div>

            <div class="t-sep"></div>

            <table class="t-meta">
                <?php if (($p[$tpl . '_show_ref'] ?? '1') == '1'): ?>
                    <tr <?php echo $f('show_ref'); ?>>
                        <td><?php echo $is_ticket ? 'TICKET:' : 'REF:'; ?></td>
                        <td class="t-bold"><?php echo $ref; ?></td>
                    </tr>
                <?php endif; ?>
                <?php if (($p[$tpl . '_show_datetime'] ?? '1') == '1'): ?>
                    <tr <?php echo $f('show_datetime'); ?>>
                        <td><?php echo $is_ticket ? 'TIME:' : 'DATE:'; ?></td>
                        <td><?php echo $datetime; ?></td>
                    </tr>
                <?php endif; ?>
                <?php if (($p[$tpl . '_show_server'] ?? '1') == '1'): ?>
                    <tr <?php echo $f('show_server'); ?>>
                        <td><?php echo $is_ticket ? 'STATION:' : 'SERVED BY:'; ?></td>
                        <td>Admin</td>
                    </tr>
                <?php endif; ?>
                <?php if (($p[$tpl . '_show_table'] ?? '1') == '1'): ?>
                    <tr <?php echo $f('show_table'); ?>>
                        <td>TABLE:</td>
                        <td><?php echo $table; ?> / <?php echo $room; ?> (<?php echo $guests; ?> guests)</td>
                    </tr>
                <?php endif; ?>
                <?php if (($p[$tpl . '_show_customer'] ?? '1') == '1'): ?>
                    <tr <?php echo $f('show_customer'); ?>>
                        <td>CUSTOMER:</td>
                        <td><?php echo $customer; ?></td>
                    </tr>
                <?php endif; ?>
            </table>

            <div class="t-sep"></div>

            <table class="t-items">
                <thead>
                    <tr class="t-bold">
                        <td style="width:22%;">QTY</td>
                        <td>ITEM</td>
                        <?php if (($p[$tpl . '_show_prices'] ?? '1') == '1'): ?>
                            <td style="width:28%;text-align:right;">AMOUNT</td>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td><?php echo $it['qty']; ?> x</td>
                            <td class="<?php echo $is_ticket ? 't-bold' : ''; ?>" style="<?php echo $is_ticket ? 'font-size:14px;' : ''; ?>"><?php echo htmlspecialchars($it['name']); ?></td>
                            <?php if (($p[$tpl . '_show_prices'] ?? '1') == '1'): ?>
                                <td style="text-align:right;">GH&#8373;<?php echo number_format($it['price'] * $it['qty'], 2); ?></td>
                            <?php endif; ?>
                        </tr>
                        <?php if (($p[$tpl . '_show_requests'] ?? '1') == '1' && $it['request'] !== ''): ?>
                            <tr>
                                <td></td>
                                <td class="t-note" colspan="<?php echo (($p[$tpl . '_show_prices'] ?? '1') == '1') ? 2 : 1; ?>">** <?php echo htmlspecialchars($it['request']); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="t-sep"></div>

            <?php if (($p[$tpl . '_show_totals'] ?? '1') == '1'): ?>
                <div <?php echo $f('show_totals'); ?>>
                    <div class="t-row"><span>Subtotal</span><span>GH&#8373;<?php echo number_format($subtotal, 2); ?></span></div>
                    <div class="t-row t-bold t-large"><span>TOTAL</span><span>GH&#8373;<?php echo number_format($total, 2); ?></span></div>
                </div>
            <?php endif; ?>

            <?php if (($p[$tpl . '_show_tax'] ?? '1') == '1'): ?>
                <div <?php echo $f('show_tax'); ?>>
                    <div class="t-sep t-dashed"></div>
                    <div class="t-row t-small"><span>Tax (VAT 15% incl.)</span><span>GH&#8373;<?php echo number_format($tax_total, 2); ?></span></div>
                </div>
            <?php endif; ?>

            <?php if ($tpl === 'payment'): ?>
                <div class="t-sep t-dashed"></div>
                <div class="t-row"><span>Payment Method</span><span><?php echo $method; ?></span></div>
                <div class="t-row"><span>Amount Paid</span><span>GH&#8373;<?php echo number_format($paid, 2); ?></span></div>
                <div class="t-row"><span>Change</span><span>GH&#8373;<?php echo number_format($change, 2); ?></span></div>
            <?php endif; ?>

            <div class="t-sep"></div>

            <div class="t-center t-small" data-field="footer_note"><?php echo htmlspecialchars($p[$tpl . '_footer_note'] ?? ''); ?></div>
        </div>
        <?php
        return ob_get_clean();
    }
}