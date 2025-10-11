# MainWP Activity Log Extension

Manage and sync activity log settings from your MainWP Dashboard to connected child sites using [activitylog.io](https://activitylog.io/).  
This extension provides centralised control and visibility of site-based activity logs, making it easy to review events and apply consistent settings across your network.

---

## 📦 Overview

The **MainWP Activity Log Extension** works together with a lightweight **child plugin** that runs on each connected site.  
It allows you to:

- 🖥 **View site-based activity logs** directly in your MainWP Dashboard  
- 🔄 **Sync settings** to a child sites from one central location  
- ⚙️ **Manage activity logging behavior** remotely  
- 🔐 Improve security and auditing through centralized log visibility

This project integrates seamlessly with [MainWP](https://mainwp.com/) and [activitylog.io](https://activitylog.io/).

---

## 🧩 Components

- **`mainwp-activity-log-ext`** — MainWP Dashboard Extension  
  - Provides the MainWP user interface  
  - Syncs settings and fetches logs from child sites  
  - Supports updates via [UUPD](https://github.com/stingray82/UUPD) custom updater.

- **`mwalx-child-activity-log`** — Child Site Plugin  
  - Handles local log collection via activitylog.io  
  - Receives and applies settings from MainWP  
  - Returns event data to the MainWP dashboard
  - Supports updates via [UUPD](https://github.com/stingray82/UUPD) custom updater.

---

## 🚀 Installation

### 1. Install the Extension on your MainWP Dashboard site
1. Download the latest release:  
   👉 [MainWP Activity Log Extension](https://github.com/stingray82/mainwp-activity-log/releases/latest)
2. Go to **MainWP Dashboard → Extensions → Install Extension**.
3. Upload the `mainwp-activity-log-ext.zip` file and **activate** it.

### 2. Install the Child Plugin on each connected site
1. Download the child plugin:  
   👉 [Child Plugin](https://github.com/stingray82/mwalx-child-activity-log/releases/latest)
2. Install it like a normal WordPress plugin:
   - WordPress Admin → Plugins → Add New → Upload Plugin
   - Activate the plugin.

*(Tip: You can also deploy the child plugin using MainWP’s Bulk Install feature.)*

---

## 🔐 Requirements

- WordPress **6.5** or higher  
- PHP **8.0** or higher  
- MainWP Dashboard installed on your management site  
- activitylog.io plugin installed on child sites

---

## ⚡ Usage

1. Once installed on both the MainWP Dashboard and child sites, visit the **MainWP Dashboard** .  
2. Navigate to a site you want 
3. Open the **Activity Log** site Tab
4. Sync if no data is shown

## 🔄 Updating

This plugin uses the [UUPD Updater](https://github.com/stingray82/UUPD) system.  
When a new version is available:

- It will show as a standard update in your **Plugins** or **Extensions** list.  

---

## 🧰 Developer Notes

- Main file constants:
  - `RUP_MAINWP_MWALX_MWALX_MAIN_FILE` — used as extension identifier  
  - `RUP_MAINWP_MWALX_MWALX_PLUGIN_DIR` / `PLUGIN_URL` — plugin base path
- Supports custom icon and banners for MainWP interface

---

## 🐛 Reporting Issues

If you run into problems or bugs:

- Open an [issue](https://github.com/stingray82/mainwp-activity-log/issues)
- Include:
  - WordPress and PHP versions
  - MainWP version
  - Log file or error message if available

---

## 📝 License

GPL 2.0 or later.  
Copyright © [ReallyUsefulPlugins.com](https://reallyusefulplugins.com)

---

## 🙌 Credits

- [MainWP](https://mainwp.com/)  
- [activitylog.io](https://activitylog.io/)  
- [UUPD](https://github.com/stingray82/UUPD) updater  
- Developed by [ReallyUsefulPlugins.com](https://reallyusefulplugins.com)

---

*This is not an official MainWP extension — it is a community project designed to enhance activity log management for MainWP users.*
