<?php
// profile/companies.php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'add') {
        $name = clean_input($_POST['name']);
        $email = clean_input($_POST['email']);
        $phone = clean_input($_POST['phone']);
        $address = clean_input($_POST['address']);
        $location = clean_input($_POST['location']);

        $stmt = $conn->prepare("INSERT INTO companies (user_id, name, email, phone, address, location) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $name, $email, $phone, $address, $location]);
    } elseif ($_POST['action'] == 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $conn->prepare("DELETE FROM companies WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user_id]);
    }
    header('Location: companies.php');
    exit;
}

// Fetch Companies
$stmt = $conn->prepare("SELECT * FROM companies WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$companies = $stmt->fetchAll();

include '../includes/header.php';
?>

<section class="container" style="padding: 2rem 0;">
    <div style="max-width: 900px; margin: 0 auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <h1><i class="fas fa-building"></i> My Companies</h1>
            <button onclick="document.getElementById('addCompanyForm').style.display='block'" class="btn btn-primary"
                style="background: var(--primary-color); border: none; color: white; padding: 0.8rem 1.5rem; border-radius: 8px; cursor: pointer; font-weight: 600;">
                <i class="fas fa-plus"></i> Add New Company
            </button>
        </div>

        <!-- Add Company Form (Hidden by default) -->
        <div id="addCompanyForm"
            style="display: none; background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); margin-bottom: 2rem;">
            <h3 style="margin-top: 0; margin-bottom: 1.5rem;">Register a New Company</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Company Name *</label>
                        <input type="text" name="name" required
                            style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Contact Email</label>
                        <input type="email" name="email"
                            style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Phone Number</label>
                        <input type="text" name="phone"
                            style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px;">
                    </div>
                    <div>
                        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Location/City</label>
                        <input type="text" name="location"
                            style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px;">
                    </div>
                    <div style="grid-column: span 2;">
                        <label style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Full Delivery
                            Address</label>
                        <textarea name="address"
                            style="width: 100%; padding: 0.8rem; border: 1px solid #ddd; border-radius: 8px; min-height: 80px;"></textarea>
                    </div>
                </div>
                <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-primary"
                        style="background: var(--primary-color); color: white; border: none; padding: 0.8rem 2rem; border-radius: 8px; cursor: pointer; font-weight: 600;">Save
                        Company</button>
                    <button type="button" onclick="document.getElementById('addCompanyForm').style.display='none'"
                        style="background: #f0f0f0; border: none; padding: 0.8rem 2rem; border-radius: 8px; cursor: pointer;">Cancel</button>
                </div>
            </form>
        </div>

        <!-- Companies List -->
        <?php if (empty($companies)): ?>
            <div
                style="text-align: center; padding: 4rem; background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);">
                <i class="fas fa-building" style="font-size: 3rem; color: #eee; margin-bottom: 1rem;"></i>
                <p style="color: #888;">No companies saved yet. Add one to simplify group orders.</p>
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem;">
                <?php foreach ($companies as $company): ?>
                    <div
                        style="background: white; padding: 1.5rem; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border: 1px solid #f0f0f0; position: relative;">
                        <h3 style="margin: 0 0 1rem 0; color: #333;">
                            <?php echo htmlspecialchars($company['name']); ?>
                        </h3>
                        <div style="font-size: 0.9rem; color: #666; display: grid; gap: 0.5rem;">
                            <span><i class="fas fa-map-marker-alt" style="width: 20px;"></i>
                                <?php echo htmlspecialchars($company['location'] ?: 'N/A'); ?>
                            </span>
                            <span><i class="fas fa-phone" style="width: 20px;"></i>
                                <?php echo htmlspecialchars($company['phone'] ?: 'N/A'); ?>
                            </span>
                            <span><i class="fas fa-envelope" style="width: 20px;"></i>
                                <?php echo htmlspecialchars($company['email'] ?: 'N/A'); ?>
                            </span>
                        </div>
                        <div
                            style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #f9f9f9; display: flex; justify-content: flex-end;">
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this company?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $company['id']; ?>">
                                <button type="submit"
                                    style="background: none; border: none; color: #ff6b6b; cursor: pointer; font-size: 0.9rem;">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include '../includes/footer.php'; ?>