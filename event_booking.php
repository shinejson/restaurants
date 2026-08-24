<?php
session_start();
require_once 'config/db.php';
require_once 'includes/functions.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: auth/login.php');
    exit();
}

$package_id = isset($_GET['package_id']) ? (int) $_GET['package_id'] : 0;

// Fetch package
$stmt = $conn->prepare("SELECT * FROM event_packages WHERE id = ?");
$stmt->execute([$package_id]);
$package = $stmt->fetch();

if (!$package) {
    header('Location: events.php');
    exit();
}

$error = '';
$success = '';

// Handle Booking Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid request';
    } else {
        $event_date = clean_input($_POST['event_date']);
        $guest_count = (int) $_POST['guest_count'];

        // Basic Validation
        if (strtotime($event_date) < strtotime('tomorrow')) {
            $error = 'Event date must be in the future.';
        } elseif ($guest_count < 1) {
            $error = 'Guest count must be at least 1.';
        } else {
            try {
                $conn->beginTransaction();

                // 1. Calculate base total
                $base_total = $package['base_price_per_head'] * $guest_count;
                $materials_total = 0;

                // Process Materials to calculate total first
                $materials = [];
                if (isset($_POST['material_name'])) {
                    for ($i = 0; $i < count($_POST['material_name']); $i++) {
                        $m_name = clean_input($_POST['material_name'][$i]);
                        $m_qty = (int) $_POST['material_qty'][$i];
                        $m_price = (float) $_POST['material_price'][$i]; // User inputs estimated price, admin confirms

                        if (!empty($m_name) && $m_qty > 0) {
                            $line_total = $m_qty * $m_price;
                            $materials_total += $line_total;
                            $materials[] = [
                                'name' => $m_name,
                                'quantity' => $m_qty,
                                'unit_price' => $m_price,
                                'total_price' => $line_total
                            ];
                        }
                    }
                }

                $grand_total = $base_total + $materials_total;

                // 2. Insert Booking
                $stmt = $conn->prepare("INSERT INTO event_bookings (user_id, package_id, event_date, guest_count, total_amount, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $package_id,
                    $event_date,
                    $guest_count,
                    $grand_total
                ]);
                $booking_id = $conn->lastInsertId();

                // 3. Insert Materials
                if (!empty($materials)) {
                    $m_stmt = $conn->prepare("INSERT INTO event_materials (booking_id, name, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)");
                    foreach ($materials as $mat) {
                        $m_stmt->execute([
                            $booking_id,
                            $mat['name'],
                            $mat['quantity'],
                            $mat['unit_price'],
                            $mat['total_price']
                        ]);
                    }
                }

                $conn->commit();
                // Redirect to profile or confirmation
                header("Location: profile.php?tab=orders&msg=booking_success"); // Or a dedicated success page
                exit();

            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error placing booking: " . $e->getMessage();
            }
        }
    }
}

include 'includes/header.php';
?>

<div class="container" style="padding: 4rem 1rem; max-width: 800px;">
    <div style="margin-bottom: 2rem;">
        <a href="events.php" style="color: #666; text-decoration: none;"><i class="fas fa-arrow-left"></i> Back to
            Events</a>
    </div>

    <div style="background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); overflow: hidden;">
        <div style="background: var(--primary-color); color: white; padding: 2rem;">
            <h1 style="margin: 0; font-size: 1.8rem;">Book
                <?php echo htmlspecialchars($package['name']); ?>
            </h1>
            <p style="margin: 0.5rem 0 0; opacity: 0.9;">Base Price:
                <?php echo format_currency($package['base_price_per_head']); ?> per guest
            </p>
        </div>

        <div style="padding: 2rem;">
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="bookingForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
                    <div class="form-group">
                        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Event Date *</label>
                        <input type="date" name="event_date" class="form-control" required
                            min="<?php echo date('Y-m-d', strtotime('tomorrow')); ?>">
                    </div>
                    <div class="form-group">
                        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Number of Guests
                            *</label>
                        <input type="number" name="guest_count" id="guest_count" class="form-control" required min="1"
                            value="50">
                    </div>
                </div>

                <div style="margin-bottom: 2rem;">
                    <h3
                        style="font-size: 1.2rem; border-bottom: 2px solid #eee; padding-bottom: 0.5rem; margin-bottom: 1rem;">
                        Additional Materials / Requirements
                        <button type="button" id="addMaterialBtn"
                            style="float: right; font-size: 0.9rem; background: #eee; border: none; padding: 0.4rem 1rem; border-radius: 4px; cursor: pointer;">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </h3>
                    <p style="font-size: 0.9rem; color: #666; margin-bottom: 1rem;">Add any extra items you need (e.g.,
                        Projector, Extra Chairs). Pricing is estimated and verified by admin.</p>

                    <div id="materialsContainer">
                        <!-- Dynamic Rows -->
                    </div>
                </div>

                <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span style="color: #666;">Package Cost:</span>
                        <span id="packageCostDisplay">0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                        <span style="color: #666;">Materials Cost:</span>
                        <span id="materialsCostDisplay">0.00</span>
                    </div>
                    <div
                        style="display: flex; justify-content: space-between; font-weight: 700; font-size: 1.2rem; color: var(--dark-color); border-top: 1px solid #ddd; padding-top: 0.5rem;">
                        <span>Estimated Total:</span>
                        <span id="totalDisplay">0.00</span>
                    </div>
                </div>

                <button type="submit" class="btn-submit" style="width: 100%; padding: 1rem; font-size: 1.1rem;">
                    Submit Booking Request
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    const basePrice = <?php echo $package['base_price_per_head']; ?>;
    const guestInput = document.getElementById('guest_count');
    const materialsContainer = document.getElementById('materialsContainer');
    const addMaterialBtn = document.getElementById('addMaterialBtn');

    function calculateTotal() {
        const guests = parseInt(guestInput.value) || 0;
        const pkgTotal = guests * basePrice;

        let matTotal = 0;
        document.querySelectorAll('.material-row').forEach(row => {
            const qty = parseFloat(row.querySelector('.mat-qty').value) || 0;
            const price = parseFloat(row.querySelector('.mat-price').value) || 0;
            matTotal += (qty * price);
        });

        const grandTotal = pkgTotal + matTotal;

        document.getElementById('packageCostDisplay').textContent = pkgTotal.toFixed(2);
        document.getElementById('materialsCostDisplay').textContent = matTotal.toFixed(2);
        document.getElementById('totalDisplay').textContent = grandTotal.toFixed(2);
    }

    function addMaterialRow() {
        const div = document.createElement('div');
        div.className = 'material-row';
        div.style.marginBottom = '1rem';
        div.style.display = 'grid';
        div.style.gridTemplateColumns = '2fr 1fr 1fr 30px';
        div.style.gap = '10px';

        div.innerHTML = `
        <input type="text" name="material_name[]" class="form-control" placeholder="Item Name (e.g. Projector)" required>
        <input type="number" name="material_qty[]" class="form-control mat-qty" placeholder="Qty" min="1" required oninput="calculateTotal()">
        <input type="number" name="material_price[]" class="form-control mat-price" placeholder="Est. Price" min="0" step="0.01" required oninput="calculateTotal()">
        <button type="button" onclick="this.parentElement.remove(); calculateTotal()" style="background: none; border: none; color: #ff6b6b; cursor: pointer;">
            <i class="fas fa-times"></i>
        </button>
    `;
        materialsContainer.appendChild(div);
    }

    guestInput.addEventListener('input', calculateTotal);
    addMaterialBtn.addEventListener('click', addMaterialRow);

    // Init
    calculateTotal();
</script>

<?php include 'includes/footer.php'; ?>