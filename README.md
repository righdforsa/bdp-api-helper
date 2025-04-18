# BDP API Helper

**BDP API Helper** is a lightweight WordPress plugin that extends the [Business Directory Plugin (BDP)](https://businessdirectoryplugin.com/) by exposing BDP custom fields via the WordPress REST API and validating field updates for clean, safe automation.

## 🎯 Goal

This plugin enables developers to **reliably automate** updates to BDP listings through the REST API by:

- Dynamically listing available BDP custom fields (`meta` fields) via a `/fields` API endpoint
- Validating updates to ensure only real, existing fields can be modified
- Rejecting invalid API update attempts with clear error messages
- Providing a professional-grade, reliable foundation for external automation (e.g., Python scripts, Zapier integrations)

---

## 📦 Installation

1. Clone or download this repository.
2. Upload the `bdp-api-helper` folder into your WordPress site's `wp-content/plugins/` directory.
3. Activate the **BDP API Helper** plugin via WordPress Admin → Plugins.

✅ Requires Business Directory Plugin (BDP) to be installed and active.  
✅ Compatible with standard WordPress REST API (`/wp-json`).

---

## 🛠️ Usage

### 1. List Available BDP Fields

Retrieve all BDP custom fields currently available for API updates.

**Request:**

```bash
curl https://yourdomain.com/wp-json/bdp-api-helper/v1/fields

