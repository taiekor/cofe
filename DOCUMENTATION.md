# Quote Requests Lite - Documentation

**Version:** 2.7.0
**Author:** Zadkiel
**Requires:** WordPress + WooCommerce

---

## What does this plugin do?

Quote Requests Lite transforms your WooCommerce store into a **quotation-based catalog**. Instead of showing prices and a traditional shopping cart, customers browse your products and add them to a **quotation list**. When ready, they fill out a contact form and submit their quote request. Both the customer and your team receive an email with the details.

This is ideal for businesses where prices are negotiated, vary by project, or require manual quoting (construction, industrial supplies, B2B services, etc.).

---

## Requirements

- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.0+

---

## Installation

1. Upload `quote-requests-lite.php` to `wp-content/plugins/`
2. Go to **Plugins** in WordPress admin and activate **Quote Requests Lite**
3. On activation, the plugin automatically creates a page called **"Quotation"** with the shortcode `[qrl_quote_checkout]`

That's it. The plugin works out of the box.

---

## How it works

### For customers (frontend)

1. **Browse products** - All product prices are hidden. The "Add to Cart" button is replaced with **"Add to Quotation"**
2. **Add products** - Clicking the button adds the product to the quotation list. The customer stays on the same page (no redirect to cart)
3. **View quotation** - On single product pages, a **"View Quotation"** button appears when there are items in the list
4. **Quotation page** - Shows a table with all added products (name and SKU). Below the table is a contact form
5. **Remove items** - Each product row has an **x** button to remove it from the list
6. **Submit request** - The customer fills in their name, email, phone (with country code), and an optional message, then clicks **"Send Quote Request"**
7. **Confirmation** - After submitting, a green success screen confirms the request was sent and that a confirmation email is on its way

### For the admin (backend)

1. **Quotes List** - Under **WooCommerce > Quotes List**, view all received quote requests in a table with:
   - Quote number (linked to the WooCommerce order)
   - Date
   - Customer name and email
   - Phone number
   - Location (automatically detected from the phone country code)
   - Products requested
   - Customer message

2. **CSV Export** - Click **"Download CSV"** to export all quotes as a spreadsheet with columns:
   - Name, Phone, Email, Location, Products, Message, Date

3. **Settings** - Under **WooCommerce > Quote Settings**, configure all plugin options

---

## Admin pages

### WooCommerce > Quotes List

A table showing all received quote requests. Each row displays:

| Column   | Description                                                        |
|----------|--------------------------------------------------------------------|
| #        | Quote/order ID. Click to open the order in WooCommerce             |
| Date     | Date and time the request was submitted                            |
| Customer | Name and email (clickable mailto link)                             |
| Phone    | Full phone number with country code                                |
| Location | Country detected from the phone country code (e.g. +52 = Mexico)  |
| Products | List of requested products with SKU                                |
| Message  | Customer's additional message (truncated to 100 chars in the list) |

**Download CSV** button in the top right exports all data to a CSV file compatible with Excel, Google Sheets, etc.

### WooCommerce > Quote Settings

All plugin configuration organized in sections:

#### General
| Setting                    | Description                                              | Default             |
|----------------------------|----------------------------------------------------------|---------------------|
| Quotation Page             | Link to edit/view the quotation page                     | Auto-created        |
| Button text                | Text on the "Add to Quotation" buttons                   | Add to Quotation    |
| View Quotation button text | Text on the "View Quotation" button (single product)     | View Quotation      |
| Button CSS class           | CSS class for the add-to-quotation button                | qrl-quote-btn       |

#### Form
| Setting                       | Description                                    | Default                    |
|-------------------------------|------------------------------------------------|----------------------------|
| Submit button text            | Text on the form submit button                 | Send Quote Request         |
| Continue button text          | Text on the "continue browsing" button         | Continue browsing products |
| Continue URL                  | URL where the continue button takes the user   | Homepage                   |
| Enable project details field  | Show/hide the optional message textarea        | Yes                        |
| Project details label         | Label for the message field                    | Additional message or specifications (optional) |
| Project details placeholder   | Placeholder text for the message field         | Add any specifications...  |

#### Emails
| Setting                         | Description                                           |
|---------------------------------|-------------------------------------------------------|
| Internal recipients             | Comma-separated email addresses that receive quotes   |
| From name                       | Sender name for emails                                |
| From email                      | Sender email address                                  |
| Subject (customer)              | Email subject for customer confirmation               |
| Email template (customer)       | Full HTML template for the customer email              |
| Subject (internal)              | Email subject for internal notification                |
| Email template (internal)       | Full HTML template for the internal email              |

**Available placeholders** for email templates:

| Placeholder          | Replaced with                          |
|----------------------|----------------------------------------|
| `{{customer_name}}`  | Customer's name                        |
| `{{customer_email}}` | Customer's email                       |
| `{{customer_phone}}` | Customer's phone (with country code)   |
| `{{quote_id}}`       | Quote/order number                     |
| `{{quote_date}}`     | Date of submission                     |
| `{{items_table}}`    | HTML table with requested products     |
| `{{project_details}}`| Customer's additional message          |
| `{{admin_link}}`     | Link to open the quote in admin (internal only) |
| `{{site_name}}`      | Your website name                      |

#### Anti-spam / Rate limiting
| Setting              | Description                                    | Default |
|----------------------|------------------------------------------------|---------|
| Enable anti-spam     | Activate rate limiting by IP                   | Yes     |
| Max submissions      | Maximum quote requests per IP in the time window | 3     |
| Time window (minutes)| How long before the counter resets             | 10      |

#### Custom CSS
A textarea where you can add custom CSS that gets injected on every page. Use this to style the quotation button, form, or any plugin element.

---

## Shortcode

```
[qrl_quote_checkout]
```

Renders the full quotation page: product table + contact form. This shortcode is automatically placed in the "Quotation" page created during activation.

You can place it on any page if needed.

---

## Key features

### Price hiding
All product prices are hidden across the entire site: shop loop, single product, cart, and checkout. The plugin suppresses WooCommerce price HTML and cart subtotals.

### Cart/Checkout blocking
The traditional WooCommerce cart and checkout pages redirect to the Quotation page. Customers interact only with the quotation system.

### Cache prevention
The quotation page sends no-cache headers and sets `DONOTCACHEPAGE` to ensure customers always see their current product list, even with caching plugins active.

### Anti-spam protection
Built-in rate limiting prevents abuse. Configurable max submissions per IP within a time window. Duplicate submission detection (same IP + email within 10 seconds) prevents accidental double submissions.

### Double-submit prevention
The form disables the submit button and shows "Sending..." after the first click to prevent duplicate submissions from impatient clicks.

### WooCommerce order integration
Each quote request creates a WooCommerce order with status **"Quote requested"**. This means:
- Quotes appear in WooCommerce > Orders (filterable by status)
- You can use WooCommerce's order management to track quotes
- Order notes, status changes, and all WooCommerce order features are available

### Location detection from phone
The plugin includes a mapping of 150+ international calling codes to country names. When a customer enters their phone with country code (e.g., +52), the system automatically identifies their location (Mexico) for the Quotes List and CSV export.

### CSV export
Download all quote requests as a CSV file with: Name, Phone, Email, Location, Products, Message, Date. The file includes a UTF-8 BOM for correct encoding in Excel.

---

## Country codes supported

The plugin recognizes country codes from all regions:

- **Americas:** USA/Canada (+1), Mexico (+52), Brazil (+55), Argentina (+54), Colombia (+57), Chile (+56), Peru (+51), Venezuela (+58), Ecuador (+593), Guatemala (+502), Cuba (+53), Bolivia (+591), Dominican Republic (+1809), Honduras (+504), Paraguay (+595), El Salvador (+503), Nicaragua (+505), Costa Rica (+506), Panama (+507), Uruguay (+598), Puerto Rico (+1787), Haiti (+509), Belize (+501), Guyana (+592), Suriname (+597), and more
- **Europe:** UK (+44), Germany (+49), France (+33), Spain (+34), Italy (+39), Netherlands (+31), Portugal (+351), Sweden (+46), Norway (+47), Denmark (+45), Finland (+358), Poland (+48), Ukraine (+380), and 30+ more
- **Asia:** China (+86), India (+91), Japan (+81), South Korea (+82), Indonesia (+62), Philippines (+63), Thailand (+66), Vietnam (+84), Malaysia (+60), Singapore (+65), Saudi Arabia (+966), UAE (+971), Israel (+972), Turkey (+90), and 40+ more
- **Africa:** Nigeria (+234), South Africa (+27), Egypt (+20), Kenya (+254), Ghana (+233), Morocco (+212), Ethiopia (+251), Tanzania (+255), and 40+ more
- **Oceania:** Australia (+61), New Zealand (+64), Fiji (+679), Papua New Guinea (+675), and more

---

## File structure

The plugin is a single file:

```
quote-requests-lite.php    # Everything: plugin header, class, hooks, admin, frontend
```

No additional folders, assets, or dependencies beyond WooCommerce.

---

## Frequently asked questions

**Can I change the button text?**
Yes. Go to WooCommerce > Quote Settings > General.

**Can I customize the emails?**
Yes. The email templates are fully editable HTML with placeholders. Go to WooCommerce > Quote Settings > Emails.

**Where do I see the quote requests?**
WooCommerce > Quotes List shows all requests in a table. You can also see them in WooCommerce > Orders filtered by "Quote requested" status.

**How do I export the data?**
Go to WooCommerce > Quotes List and click "Download CSV" in the top right corner.

**Can I add the quotation form to a different page?**
Yes. Add the shortcode `[qrl_quote_checkout]` to any page.

**Does it work with caching plugins?**
Yes. The quotation page automatically disables caching to ensure customers always see current data.

**What happens if WooCommerce is deactivated?**
The plugin will not load its functionality. It requires WooCommerce to be active.
