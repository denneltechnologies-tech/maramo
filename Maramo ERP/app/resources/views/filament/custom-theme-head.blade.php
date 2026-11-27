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

  /* Sleek modern sidebar */
  .fi-sidebar {
    border-right: 1px solid rgba(0, 0, 0, 0.06) !important;
    background: #faf8f5 !important;
  }

  .fi-sidebar-nav {
    padding-top: 0.5rem;
  }

  .fi-sidebar-group-label span {
    font-size: 0.68rem !important;
    text-transform: uppercase !important;
    letter-spacing: 0.08em !important;
    font-weight: 700 !important;
    color: #8d7b73 !important;
  }

  .fi-sidebar-item-active .fi-sidebar-item-button {
    background: rgba(190, 123, 86, 0.12) !important;
    color: #8d5038 !important;
    font-weight: 600 !important;
    border-radius: 10px !important;
  }

  .fi-sidebar-item-button {
    border-radius: 10px !important;
    transition: all 0.18s ease-in-out !important;
  }

  .fi-sidebar-item-button:hover {
    background: rgba(0, 0, 0, 0.04) !important;
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
    border-radius: 16px !important;
    border: 1px solid rgba(0, 0, 0, 0.06) !important;
    box-shadow: 0 4px 20px -2px rgba(45, 34, 28, 0.04) !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }

  .fi-wi-stats-overview-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px -2px rgba(45, 34, 28, 0.08) !important;
  }

  /* Modern Pill Badges */
  .fi-badge {
    border-radius: 9999px !important;
    padding: 0.2rem 0.65rem !important;
    font-weight: 600 !important;
    font-size: 0.75rem !important;
    letter-spacing: 0.02em !important;
  }

  /* Modern Table Styling */
  .fi-ta-header-cell {
    font-size: 0.72rem !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.05em !important;
    color: #6b5c54 !important;
  }

  .fi-ta-row:hover {
    background-color: rgba(190, 123, 86, 0.02) !important;
  }

  /* Buttons */
  .fi-btn {
    border-radius: 10px !important;
    font-weight: 600 !important;
    transition: all 0.18s ease-in-out !important;
  }

  .fi-btn-primary {
    background: linear-gradient(135deg, #be7b56 0%, #aa6544 100%) !important;
    box-shadow: 0 3px 12px rgba(190, 123, 86, 0.25) !important;
  }

  .fi-btn-primary:hover {
    background: linear-gradient(135deg, #aa6544 0%, #8d5038 100%) !important;
    transform: translateY(-1px);
    box-shadow: 0 5px 16px rgba(190, 123, 86, 0.35) !important;
  }

  /* Interactive Tabs on CRM List */
  .fi-tabs {
    border-bottom: 1px solid rgba(0, 0, 0, 0.06) !important;
    margin-bottom: 1.25rem !important;
  }

  .fi-tabs-item {
    font-weight: 600 !important;
    border-radius: 8px 8px 0 0 !important;
    padding: 0.6rem 1rem !important;
  }

  .fi-tabs-item-active {
    color: #8d5038 !important;
    border-bottom: 2px solid #be7b56 !important;
  }
</style>
