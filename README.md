# AgeWallet PHP Client

A single-file, drop-in PHP client for [AgeWallet](https://agewallet.io) age verification.

Zero dependencies. No Composer required. Just PHP with OpenSSL.

## Features

- OAuth2/OIDC Authorization Code Flow with PKCE
- JWT signature verification via JWKS
- Full claim validation (issuer, audience, expiry, nonce)
- Session-based storage
- Returns simple binary result (1 or 0)

## Requirements

- PHP 7.4+
- OpenSSL extension (standard)
- Sessions enabled

## Installation

Download `agewallet-client.php` and drop it into your project.

```bash
curl -O https://raw.githubusercontent.com/AgeWallet/agewallet-php-client/main/agewallet-client.php
```

## Configuration

Edit the configuration section at the top of `agewallet-client.php`:

```php
$env = 'prod'; // 'prod' or 'dev'

$GLOBALS['agewallet_config'] = [
    'client_id'     => 'YOUR_CLIENT_ID',      // From AgeWallet dashboard
    'client_secret' => '',                     // Optional for public clients
    'redirect_uri'  => 'https://yoursite.com/', // Must match AgeWallet config
    // ...
];
```

| Setting | Description |
|---------|-------------|
| `client_id` | Your AgeWallet client ID |
| `client_secret` | Client secret (optional with PKCE) |
| `redirect_uri` | URL where AgeWallet redirects after auth |
| `env` | `'prod'` for app.agewallet.io, `'dev'` for dev.agewallet.io |

## Usage

### Standalone Mode

Access the script directly via URL:

| Endpoint | Returns | Description |
|----------|---------|-------------|
| `?action=check` | `1` or `0` | Check verification status |
| `?action=start` | (redirect) | Start OAuth flow |
| `?action=callback` | `1` or `0` | Process OAuth callback |
| `?action=reset` | `0` | Clear verification |

### Include Mode

Include in your PHP files to use the API functions:

```php
<?php
define('AGEWALLET_INCLUDE', true);
require 'agewallet-client.php';

if (agewallet_is_verified()) {
    echo "User is verified!";
}
```

## API Reference

### `agewallet_is_verified(): bool`

Check if the current user has passed age verification.

```php
if (agewallet_is_verified()) {
    // Show age-restricted content
}
```

### `agewallet_get_claims(): ?array`

Get the verified claims from the ID token.

```php
$claims = agewallet_get_claims();
// ['sub' => '...', 'age_verified' => true, ...]
```

### `agewallet_reset(): void`

Clear the verification status.

```php
agewallet_reset();
```

### `agewallet_start_auth(): void`

Start the OAuth flow. Redirects to AgeWallet.

```php
agewallet_start_auth(); // Redirects, does not return
```

### `agewallet_process_callback(): bool`

Process the OAuth callback. Call from your redirect URI handler.

```php
$success = agewallet_process_callback();
if ($success) {
    // Verification successful
} else {
    // Verification failed
}
```

---

## Examples

The examples below show how site owners can implement age gating on top of this client.

### Example 1: Basic Verification Check

```php
<?php
// page.php
define('AGEWALLET_INCLUDE', true);
require 'agewallet-client.php';

if (agewallet_is_verified()) {
    echo "Welcome! You are verified.";
} else {
    echo '<a href="agewallet-client.php?action=start">Verify your age</a>';
}
```

---

### Example 2: Age Gate Handler with Deep Link Support

Create a wrapper script that handles gating, return URLs, and the gate UI.

```php
<?php
/**
 * age-gate.php - Site owner's implementation
 * 
 * Handles:
 * - Processing the callback
 * - Return URL tracking (deep link support)
 * - Gate UI rendering
 */

define('AGEWALLET_INCLUDE', true);
require_once __DIR__ . '/agewallet-client.php';

// ============================================================================
// CONFIGURATION
// ============================================================================

$GLOBALS['age_gate_config'] = [
    'gate_path'       => '/age-gate.php',    // Absolute path to this file
    'fail_url'        => '/verification-failed.php',
    'overlay_title'   => 'Age Verification Required',
    'overlay_message' => 'You must be of legal age to view this content.',
    'button_text'     => 'Verify with AgeWallet',
];

// ============================================================================
// CALLBACK HANDLER
// ============================================================================

if ((isset($_GET['code']) || isset($_GET['error'])) && isset($_GET['state'])) {
    $success = agewallet_process_callback();
    $return_to = $_SESSION['age_gate_return_to'] ?? '/';
    unset($_SESSION['age_gate_return_to']);
    
    if ($success) {
        header('Location: ' . $return_to);
        exit;
    } else {
        header('Location: ' . $GLOBALS['age_gate_config']['fail_url']);
        exit;
    }
}

// ============================================================================
// PUBLIC FUNCTIONS
// ============================================================================

function age_gate_passed(): bool {
    return agewallet_is_verified();
}

function age_gate_claims(): ?array {
    return agewallet_get_claims();
}

function age_gate_reset(): void {
    agewallet_reset();
}

function age_gate_start_url(?string $return_to = null): string {
    if ($return_to === null) {
        $return_to = $_SERVER['REQUEST_URI'] ?? '/';
    }
    $gate_path = $GLOBALS['age_gate_config']['gate_path'];
    return $gate_path . '?start=1&return_to=' . urlencode($return_to);
}

function age_gate_start(?string $return_to = null): void {
    if ($return_to === null) {
        $return_to = $_SERVER['HTTP_REFERER'] ?? '/';
    }
    $_SESSION['age_gate_return_to'] = $return_to;
    agewallet_start_auth();
}

function age_gate_overlay(): string {
    if (age_gate_passed()) {
        return '';
    }
    
    $config = $GLOBALS['age_gate_config'];
    $start_url = age_gate_start_url();
    $title = htmlspecialchars($config['overlay_title']);
    $message = htmlspecialchars($config['overlay_message']);
    $button = htmlspecialchars($config['button_text']);
    
    return <<<HTML
<div id="age-gate-overlay" style="
    position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.9);
    display: flex; align-items: center; justify-content: center;
    z-index: 99999;
">
    <div style="
        background: #fff; padding: 3rem; border-radius: 8px;
        text-align: center; max-width: 400px; margin: 1rem;
    ">
        <h2 style="margin: 0 0 1rem 0; color: #333;">{$title}</h2>
        <p style="margin: 0 0 2rem 0; color: #666;">{$message}</p>
        <a href="{$start_url}" style="
            display: inline-block; padding: 1rem 2rem;
            background: #007bff; color: #fff; text-decoration: none;
            border-radius: 4px; font-weight: bold;
        ">{$button}</a>
    </div>
</div>
HTML;
}

// ============================================================================
// HANDLE START ACTION
// ============================================================================

if (isset($_GET['start'])) {
    $return_to = $_GET['return_to'] ?? $_SERVER['HTTP_REFERER'] ?? '/';
    age_gate_start($return_to);
}

// ============================================================================
// HANDLE RESET ACTION
// ============================================================================

if (isset($_GET['reset'])) {
    age_gate_reset();
    header('Location: ' . ($_GET['return_to'] ?? '/'));
    exit;
}
```

---

### Example 3: Homepage as Redirect URI

Your homepage can serve as the OAuth redirect URI. The age-gate.php include detects callbacks automatically.

```php
<?php
/**
 * index.php - Homepage (also serves as redirect_uri)
 */
require_once 'age-gate.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Wine Shop</title>
</head>
<body>
    <nav>
        <a href="index.php">Home</a>
        <a href="about.php">About</a>
        <a href="shop/collection.php">Shop</a>
        <?php if (age_gate_passed()): ?>
            <a href="age-gate.php?reset=1&return_to=/">Logout</a>
        <?php endif; ?>
    </nav>
    
    <main>
        <h1>Welcome to Wine Shop</h1>
        
        <?php if (age_gate_passed()): ?>
            <p>✓ You are age verified.</p>
        <?php else: ?>
            <p>
                Some content requires age verification.
                <a href="<?= age_gate_start_url() ?>">Verify now</a>
            </p>
        <?php endif; ?>
    </main>
</body>
</html>
```

---

### Example 4: Partial Content Gating

Show some content to everyone, restrict specific sections.

```php
<?php
/**
 * about.php - Page with partially gated content
 */
require_once 'age-gate.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>About Us</title>
</head>
<body>
    <h1>About Us</h1>
    
    <!-- Public content -->
    <p>We are a premium wine retailer founded in 1985.</p>
    
    <h2>Staff Wine Picks</h2>
    
    <?php if (age_gate_passed()): ?>
        <!-- Age-restricted content -->
        <div class="wine-picks">
            <h3>Sarah's Pick</h3>
            <p>2019 Château Margaux - $450</p>
            
            <h3>Mike's Pick</h3>
            <p>2020 Penfolds Grange - $750</p>
        </div>
    <?php else: ?>
        <div class="restricted-notice">
            <p>🔒 Age verification required to view wine recommendations.</p>
            <a href="<?= age_gate_start_url() ?>">Verify your age</a>
        </div>
    <?php endif; ?>
    
    <!-- More public content -->
    <h2>Visit Us</h2>
    <p>123 Vineyard Lane, Wine Country</p>
</body>
</html>
```

---

### Example 5: Full Page Gate with Overlay

Block the entire page with an overlay until verified.

```php
<?php
/**
 * shop/collection.php - Fully gated page
 */
require_once '../age-gate.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Wine Collection</title>
</head>
<body>
    <h1>Wine Collection</h1>
    
    <div class="wine-grid">
        <div class="wine-card">
            <h3>2019 Château Margaux</h3>
            <p>$450</p>
            <button>Add to Cart</button>
        </div>
        
        <div class="wine-card">
            <h3>2020 Opus One</h3>
            <p>$395</p>
            <button>Add to Cart</button>
        </div>
    </div>
    
    <?php 
    // This renders the gate overlay if not verified
    // Content is in the DOM but hidden behind the overlay
    echo age_gate_overlay(); 
    ?>
</body>
</html>
```

---

## Flow Diagram

```
User visits /shop/collection.php (not verified)
    │
    ▼
Page shows age gate overlay
    │
    ▼
User clicks "Verify with AgeWallet"
    │
    ▼
→ /age-gate.php?start=1&return_to=/shop/collection.php
    │
    ▼
Stores return_to in session, redirects to AgeWallet
    │
    ▼
User completes verification in AgeWallet
    │
    ▼
AgeWallet redirects to → /?code=xxx&state=xxx
    │
    ▼
age-gate.php detects callback, calls agewallet_process_callback()
    │
    ▼
On success: redirects to /shop/collection.php
    │
    ▼
Page renders without overlay (verified)
```

---

## Security

This client implements the following security measures:

- **PKCE (S256)**: Prevents authorization code interception attacks
- **State parameter**: Prevents CSRF attacks
- **Nonce validation**: Prevents token replay attacks
- **JWT signature verification**: Validates tokens using provider's JWKS
- **Claim validation**: Checks issuer, audience, expiration, and timing

## License

MIT License

## Support

- Documentation: [https://docs.agewallet.io](https://docs.agewallet.io)
- Issues: [https://github.com/AgeWallet/agewallet-php-client/issues](https://github.com/AgeWallet/agewallet-php-client/issues)
