# Maramo Beauty Institute Website

Static multi-page website for Maramo Beauty Institute (Accra, Ghana).

## Pages

- `index.html` - Home
- `about.html` - About
- `services.html` - Services
- `programs.html` - Programs / Training
- `gallery.html` - Gallery
- `contact.html` - Contact / Booking

## Local preview

Open `index.html` directly, or run:

```bash
python -m http.server 8000
```

Then visit `http://localhost:8000`.

## Deploy to Netlify

### Option A: Connect GitHub repo (recommended)

1. Create a new GitHub repository (empty, no README/license).
2. Push this project:

```bash
git remote add origin https://github.com/<your-username>/<repo-name>.git
git push -u origin main
```

3. In Netlify: **Add new site** > **Import an existing project** > **GitHub**.
4. Select the repo.
5. Build settings for this static site:
   - **Build command:** *(leave empty)*
   - **Publish directory:** `.`
6. Deploy. Netlify will give you a public URL and redeploy on every push.

### Option B: Netlify Drop (quick)

Drag and drop this folder at https://app.netlify.com/drop.

## Before publishing

- Confirm official business name, address, phone/WhatsApp, and hours.
- Confirm full service menu, pricing, and training/certification details.
- Replace or verify rights for social media images in `assets/`.
- Add official logo and brand photos.