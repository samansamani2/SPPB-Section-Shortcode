# 🧩 Joomla Plugin: Content – SP Page Builder Section Embed

## 📝 Description
**SPPB Section Embed** is a lightweight yet powerful Joomla content plugin that allows you to insert any **SP Page Builder** section directly into your Joomla articles, custom modules, or other content areas using a simple shortcode.

It gives you full control to reuse your beautifully designed Page Builder sections across your entire website — without duplicating pages or rebuilding layouts.

---

## ⚙️ Key Features
- 🔗 **Embed any section** from an SP Page Builder page using a simple `{sppb_section}` shortcode.  
- 🧭 **Select by Page ID, Alias, Label, Index, or Section ID** — whichever is more convenient.  
- ⚡ **Built-in cache support** for faster rendering and reduced database load.  
- 🔁 **Automatic asset injection** — all required SP Page Builder CSS and JS files are loaded automatically.  
- 🧩 **Fully compatible with Joomla 3.9+, 4.x, and 5.x.**  
- 🎨 **Full design consistency** — sections render exactly as they appear inside SP Page Builder.  
- 🪶 **No extra setup required** — works immediately after installation and activation.

---

## 🧠 How It Works
Once installed and enabled, simply use the following shortcode inside your Joomla article, custom module, or other content editor:

```text
{sppb_section page=2 label="Hero Section"}
```

The plugin will automatically:
1. Fetch the specified SP Page Builder page.
2. Extract only the desired section (by label, index, or ID).
3. Insert it seamlessly into your content — with all styles, scripts, and animations intact.

---

## 💡 Example Shortcodes

| Purpose | Example |
|----------|----------|
| By page ID and label | `{sppb_section page=3 label="Gallery"}` |
| By alias and label | `{sppb_section alias="home" label="Hero Banner"}` |
| By index | `{sppb_section page=5 index=1}` |
| By unique section ID | `{sppb_section page=2 id="sppb-section-1761482810567"}` |
| Display full page content | `{sppb_section page=7 echo_full=1}` |

---

## 🧰 Optional Parameters

| Parameter | Description |
|------------|-------------|
| `page` | SP Page Builder page ID. *(Required if alias not used)* |
| `alias` | Page alias (alternative to ID). |
| `label` | Admin Label of the desired section (case-insensitive). |
| `index` | Numeric index of the section within the page (starting from 0). |
| `id` | Specific section ID or data-row-id. |
| `itemid` | Optional Joomla menu item ID to inherit styles or modules. |
| `echo_full=1` | Outputs the full page instead of a single section. |
| `debug=1` | Shows debug info to help locate the target section. |

---

## 🚀 Installation & Usage
1. Download the plugin ZIP file.  
2. In Joomla Admin, go to **Extensions → Manage → Install** and upload the ZIP file.  
3. Go to **Extensions → Plugins**, find **Content – SP Page Builder Section Embed**, and enable it.  
4. Use the `{sppb_section ...}` shortcode anywhere in your content.  
5. Save and preview your article — your SP Page Builder section will appear instantly.

---

## 🧩 Compatibility
- Joomla **3.9+**, **4.x**, **5.x**  
- SP Page Builder **3.x / 5.x**  
- PHP **7.4+**

---

## 🖼️ Preview
![SPPB Section Embed Preview](assets/sppb-section-embed-preview.png)

> The plugin automatically loads SP Page Builder assets, ensuring a perfect match with the original page design.

---

## ❤️ Author’s Note
This plugin was built to bridge the gap between Joomla’s native content system and the flexibility of SP Page Builder —  
helping you **reuse, modularize, and optimize** your page layouts with minimal effort.

If you regularly use SP Page Builder, this plugin will save you hours of repetitive layout work.

---

## 📦 Changelog

### v1.0.0
- Initial public release  
- Joomla 3.x, 4.x, 5.x compatibility  
- Full support for SP Page Builder 3.x / 5.x  
- Automatic CSS/JS asset injection  
- Caching and label-based section detection  

---

## 🧠 Contribute
Contributions, pull requests, and issue reports are always welcome!  
If you’ve found a bug or have an idea for improvement, feel free to open an issue or submit a PR.

👉 [Create a New Issue](../../issues)

---

## 📄 License
This plugin is distributed under the **GNU General Public License v3.0**.  
See [LICENSE](LICENSE) for more details.

---


---

## 👨‍💻 Author
**Developer:** [Lmskaran Team]  Lmskaran.com
**Email:** [samansamani2@yahoo.com]  


---

⭐ If you like this plugin, please **star the repository** and share it with the Joomla community!
