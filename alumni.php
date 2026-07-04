<?php
require_once 'config/database.php';

// ── Check Visibility Toggle ────────────
$alumni_public_visibility = 'ON';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM admin_settings WHERE setting_name = 'alumni_public_visibility'");
    $stmt->execute();
    $alumni_public_visibility = $stmt->fetchColumn() ?: 'ON';
} catch (Exception $e) {}

// Get initials helper
function getInitials($name) {
    $words = explode(" ", preg_replace('/\s+/', ' ', trim($name)));
    $initials = "";
    if (isset($words[0])) $initials .= mb_substr($words[0], 0, 1);
    if (isset($words[1])) $initials .= mb_substr($words[1], 0, 1);
    return strtoupper($initials) ?: "PP";
}

// ── Load Alumni Data (Only if Visibility is ON) ────────────
$alumni = [];
if ($alumni_public_visibility === 'ON') {
    try {
        // Retrieve approved or verified alumni with track information
        $stmt = $pdo->prepare("
            SELECT name, profile_photo, user_photo, academic_track_after_pepp, current_profession_details
            FROM alumni
            WHERE (status = 'approved' OR is_verified = 1)
              AND (
                (academic_track_after_pepp IS NOT NULL AND academic_track_after_pepp <> '' AND academic_track_after_pepp <> '[]')
                OR
                (current_profession_details IS NOT NULL AND current_profession_details <> '' AND current_profession_details <> '{}')
              )
            ORDER BY RAND()
        ");
        $stmt->execute();
        $alumni = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Failed to load public alumni showcase: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PEPP Alumni Showcase - Success Wall</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --gold: #d4a13a;
            --gold-d: #b8861f;
            --gold-l: #fcd34d;
            --bg-dark: #0a0609;
            --bg-surface: #140d12;
            --bg-surface-elevated: #1e141b;
            --border-gold: rgba(212, 161, 58, 0.25);
            --border-gold-focus: rgba(212, 161, 58, 0.65);
            --ink: #f5f5f4;
            --muted: #a8a29e;
            --card-bg: rgba(20, 13, 18, 0.75);
            --radius: 20px;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            color: var(--ink);
            background: 
                radial-gradient(circle at 10% 20%, rgba(212, 161, 58, 0.08) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(122, 43, 79, 0.12) 0%, transparent 45%),
                var(--bg-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        /* Header bar */
        header {
            background: rgba(10, 6, 9, 0.82);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-gold);
            padding: 16px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #fff;
        }

        .brand img {
            height: 36px;
            object-fit: contain;
        }

        .brand span {
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: -0.5px;
        }

        .cta-btn {
            background: linear-gradient(135deg, #fef08a 0%, #d4a13a 50%, #b8861f 100%);
            color: #000;
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.85rem;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(212, 161, 58, 0.2);
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .cta-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(212, 161, 58, 0.35);
        }

        /* Hero Showcase Title */
        .hero {
            text-align: center;
            padding: 60px 20px 40px;
            max-width: 800px;
            margin: 0 auto;
        }

        .hero h1 {
            font-size: 2.8rem;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -1px;
            background: linear-gradient(to right, #ffffff, #d4a13a);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 12px;
        }

        .hero p {
            font-size: 1.1rem;
            color: var(--muted);
            font-weight: 500;
        }

        /* Showcase Grid */
        .container {
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            padding: 0 24px 80px;
            flex-grow: 1;
        }

        .showcase-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 30px;
        }

        /* Success Card */
        .alumni-card {
            background: var(--card-bg);
            border: 1px solid var(--border-gold);
            border-radius: var(--radius);
            padding: 28px 24px;
            text-align: center;
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .alumni-card:hover {
            transform: translateY(-6px);
            border-color: var(--gold);
            box-shadow: 0 15px 35px rgba(212, 161, 58, 0.12);
        }

        .avatar-wrap {
            position: relative;
            margin-bottom: 18px;
        }

        .avatar-img {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--gold);
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            background: var(--bg-surface-elevated);
        }

        .avatar-placeholder {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            border: 3px solid var(--gold);
            background: linear-gradient(135deg, #1e141b 0%, #2e2029 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gold);
            font-size: 1.5rem;
            font-weight: 800;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            letter-spacing: 0.5px;
        }

        .alumni-name {
            font-size: 1.15rem;
            font-weight: 800;
            color: #fff;
            margin-bottom: 14px;
            letter-spacing: -0.3px;
        }

        .track-info {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 12px;
            text-align: left;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 12px;
            padding: 12px 14px;
            border: 1px solid rgba(255, 255, 255, 0.03);
            font-size: 0.85rem;
        }

        .track-item {
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }

        .track-item i {
            color: var(--gold);
            margin-top: 3px;
            font-size: 0.9rem;
        }

        .track-title {
            font-weight: 700;
            color: #fff;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .track-detail {
            color: var(--muted);
            line-height: 1.4;
        }

        /* Unavailable State */
        .unavailable-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 80px 20px;
            max-width: 500px;
            margin: auto;
            flex-grow: 1;
        }

        .unavailable-container i {
            font-size: 4rem;
            color: var(--gold);
            margin-bottom: 20px;
            opacity: 0.8;
        }

        .unavailable-container h2 {
            font-size: 1.6rem;
            font-weight: 800;
            margin-bottom: 10px;
            color: #fff;
        }

        .unavailable-container p {
            color: var(--muted);
            font-size: 0.95rem;
        }

        footer {
            text-align: center;
            padding: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.8rem;
            color: var(--muted);
            background: #060406;
        }
    </style>
</head>
<body>

    <!-- Header bar -->
    <header>
        <div class="header-container">
            <a href="#" class="brand">
                <img src="assets/img/pepp-logo-icon.png" alt="PEPP Logo">
                <span>PEPP Showcase</span>
            </a>
            <a href="https://pepplearning.in/admissions/alumni-portal.php" class="cta-btn" target="_blank">
                <i class="fas fa-user-check"></i> Verify Profile / Alumni Login
            </a>
        </div>
    </header>

    <?php if ($alumni_public_visibility !== 'ON'): ?>
        <!-- OFF Visibility Mode -->
        <div class="unavailable-container">
            <i class="fas fa-users-slash"></i>
            <h2>Showcase Unavailable</h2>
            <p>PEPP Alumni Showcase is currently unavailable.</p>
        </div>
    <?php else: ?>
        <!-- Hero section -->
        <div class="hero">
            <h1>Our Alumni Success Wall</h1>
            <p>Discover where PEPPians are studying and working. Inspiring generations of future leaders.</p>
        </div>

        <!-- Alumni Cards Container -->
        <div class="container">
            <?php if (empty($alumni)): ?>
                <div style="text-align:center; padding: 50px; color: var(--muted);">
                    <i class="fas fa-user-graduate" style="font-size: 3rem; margin-bottom: 12px; color: var(--gold);"></i>
                    <p>No verified alumni track records found to display.</p>
                </div>
            <?php else: ?>
                <div class="showcase-grid">
                    <?php foreach ($alumni as $row):
                        // Parse academic track
                        $tracks = [];
                        if (!empty($row['academic_track_after_pepp'])) {
                            $decoded = json_decode($row['academic_track_after_pepp'], true);
                            if (is_array($decoded)) {
                                $tracks = $decoded;
                            }
                        }
                        
                        // Parse profession details
                        $prof = null;
                        if (!empty($row['current_profession_details'])) {
                            $decoded_prof = json_decode($row['current_profession_details'], true);
                            if (is_array($decoded_prof)) {
                                $prof = $decoded_prof;
                            }
                        }
                        
                        // Get photo path
                        $photo_url = '';
                        $photo_candidate = $row['profile_photo'] ?: $row['user_photo'];
                        if ($photo_candidate) {
                            $real_photo_path = dirname(__DIR__) . '/../' . ltrim($photo_candidate, '/');
                            if (file_exists($real_photo_path) || strpos($photo_candidate, 'http') === 0) {
                                $photo_url = $photo_candidate;
                                if (strpos($photo_url, 'http') !== 0 && strpos($photo_url, 'uploads/') === 0) {
                                    $photo_url = '' . $photo_url;
                                }
                            }
                        }
                    ?>
                        <div class="alumni-card">
                            <div class="avatar-wrap">
                                <?php if ($photo_url): ?>
                                    <img src="<?php echo htmlspecialchars($photo_url); ?>" alt="<?php echo htmlspecialchars($row['name']); ?>" class="avatar-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <div class="avatar-placeholder" style="display:none;"><?php echo getInitials($row['name']); ?></div>
                                <?php else: ?>
                                    <div class="avatar-placeholder"><?php echo getInitials($row['name']); ?></div>
                                <?php endif; ?>
                            </div>
                            
                            <h3 class="alumni-name"><?php echo htmlspecialchars($row['name']); ?></h3>
                            
                            <div class="track-info">
                                <?php if (!empty($tracks)): ?>
                                    <!-- Display Academic Track -->
                                    <div class="track-item">
                                        <i class="fas fa-graduation-cap"></i>
                                        <div>
                                            <div class="track-title">Academic Track</div>
                                            <?php foreach ($tracks as $t): ?>
                                                <div class="track-detail">
                                                    <strong><?php echo htmlspecialchars($t['course'] ?? ''); ?></strong><br>
                                                    <span style="font-size:0.8rem;"><?php echo htmlspecialchars($t['institute'] ?? ''); ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php elseif ($prof): ?>
                                    <!-- Display Career Track -->
                                    <div class="track-item">
                                        <i class="fas fa-briefcase"></i>
                                        <div>
                                            <div class="track-title">Career Track</div>
                                            <div class="track-detail">
                                                <strong><?php echo htmlspecialchars($prof['profession'] ?? ''); ?></strong><br>
                                                <span style="font-size:0.8rem;"><?php echo htmlspecialchars($prof['working_institute'] ?? ''); ?></span>
                                                <?php if (!empty($prof['status'])): ?>
                                                    <br><span class="badge" style="font-size: 0.72rem; text-transform: capitalize; color: var(--gold);"><?php echo htmlspecialchars($prof['status']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> PEPP Learning. All Rights Reserved.</p>
    </footer>

</body>
</html>
