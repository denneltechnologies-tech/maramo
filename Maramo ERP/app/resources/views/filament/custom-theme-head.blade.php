<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&family=Playfair+Display:ital,wght@0,600;1,400&display=swap" rel="stylesheet">

<style>
  /* Maramo Beauty Institute - Modern Luxury Theme */
  :root {
    --font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
  }

  body {
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif !important;
    letter-spacing: -0.011em;
  }

  /* 1. Ensure Full Screen Width for All Tables & Containers */
  .fi-main-ctn,
  .fi-main,
  .fi-page,
  .fi-page-content,
  .fi-section,
  .fi-ta-ctn,
  .fi-ta,
  .fi-ta-content,
  .fi-ta-table-ctn,
  .fi-ta-table {
    width: 100% !important;
    max-width: 100% !important;
  }

  .fi-ta-table {
    table-layout: auto !important;
  }

  .fi-ta-header-cell {
    padding: 0.65rem 0.85rem !important;
    font-size: 0.72rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.05em !important;
    color: #6b5c54 !important;
    white-space: nowrap !important;
  }

  .fi-ta-cell {
    padding: 0.65rem 0.85rem !important;
  }

  /* 2. Compact Sidebar - Reduce Oversized Gaps */
  .fi-sidebar {
    width: 16rem !important;
    background: #faf8f5 !important;
    border-right: 1px solid rgba(0, 0, 0, 0.06) !important;
  }

  .fi-sidebar-nav {
    padding: 0.35rem 0.65rem !important;
    gap: 0.2rem !important;
  }

  .fi-sidebar-group {
    margin-top: 0.45rem !important;
    margin-bottom: 0.25rem !important;
    padding-top: 0.15rem !important;
  }

  .fi-sidebar-group-label {
    padding-top: 0.2rem !important;
    padding-bottom: 0.2rem !important;
    margin-bottom: 0.1rem !important;
  }

  .fi-sidebar-group-label span {
    font-size: 0.66rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.08em !important;
    font-weight: 700 !important;
    color: #8d7b73 !important;
  }

  .fi-sidebar-item {
    margin-bottom: 0.1rem !important;
  }

  .fi-sidebar-item-button {
    padding-top: 0.35rem !important;
    padding-bottom: 0.35rem !important;
    padding-left: 0.65rem !important;
    padding-right: 0.65rem !important;
    min-height: 2.1rem !important;
    font-size: 0.82rem !important;
    border-radius: 9px !important;
    transition: all 0.15s ease-in-out !important;
  }

  .fi-sidebar-item-active .fi-sidebar-item-button {
    background: rgba(190, 123, 86, 0.12) !important;
    color: #8d5038 !important;
    font-weight: 600 !important;
  }

  .fi-sidebar-item-button:hover {
    background: rgba(0, 0, 0, 0.04) !important;
  }

  .fi-sidebar-item-icon {
    width: 1.15rem !important;
    height: 1.15rem !important;
  }

  /* 3. Compact Main Layout Gutters */
  .fi-main {
    padding-top: 1rem !important;
    padding-left: 1.25rem !important;
    padding-right: 1.25rem !important;
    max-width: 100% !important;
  }

  .fi-page-header {
    margin-bottom: 1rem !important;
  }

  /* Top Navigation Bar with subtle glassmorphism */
  .fi-topbar {
    background: rgba(255, 255, 255, 0.88) !important;
    backdrop-filter: blur(12px) !important;
    border-bottom: 1px solid rgba(0, 0, 0, 0.05) !important;
  }

  /* Cards & Widgets Elevation */
  .fi-wi-stats-overview-stat,
  .fi-ta,
  .fi-section {
    border-radius: 14px !important;
    border: 1px solid rgba(0, 0, 0, 0.06) !important;
    box-shadow: 0 4px 18px -2px rgba(45, 34, 28, 0.03) !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }

  .fi-wi-stats-overview-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px -2px rgba(45, 34, 28, 0.06) !important;
  }

  /* Modern Pill Badges */
  .fi-badge {
    border-radius: 9999px !important;
    padding: 0.2rem 0.65rem !important;
    font-weight: 600 !important;
    font-size: 0.74rem !important;
    letter-spacing: 0.02em !important;
    white-space: nowrap !important;
  }

  .fi-ta-row:hover {
    background-color: rgba(190, 123, 86, 0.02) !important;
  }

  /* Buttons */
  .fi-btn {
    border-radius: 9px !important;
    font-weight: 600 !important;
    transition: all 0.15s ease-in-out !important;
  }

  .fi-btn-primary {
    background: linear-gradient(135deg, #be7b56 0%, #aa6544 100%) !important;
    box-shadow: 0 3px 12px rgba(190, 123, 86, 0.22) !important;
  }

  .fi-btn-primary:hover {
    background: linear-gradient(135deg, #aa6544 0%, #8d5038 100%) !important;
    transform: translateY(-1px);
    box-shadow: 0 5px 16px rgba(190, 123, 86, 0.3) !important;
  }

  /* Interactive Tabs on CRM List */
  .fi-tabs {
    border-bottom: 1px solid rgba(0, 0, 0, 0.06) !important;
    margin-bottom: 1.25rem !important;
  }

  .fi-tabs-item {
    font-weight: 600 !important;
    border-radius: 8px 8px 0 0 !important;
    padding: 0.55rem 0.95rem !important;
  }

  .fi-tabs-item-active {
    color: #8d5038 !important;
    border-bottom: 2px solid #be7b56 !important;
  }
</style>
