# HamaraVideo

AI promo & festival video maker for Indian small businesses.
Type in Telugu / Hindi / English → get a WhatsApp-ready promo video. Pay per video with UPI.

## Stack
- Frontend: single-file mobile-first HTML (PWA later)
- Backend: PHP on Hostinger (`api/`), secrets in `api/config.local.php` (never in git)
- Video generation: Fal.ai (Kling) via `api/video-proxy.php`
- Prompt polishing: Claude API
- DB: MySQL on Hostinger (users, credits, jobs)
- Payments: UPI (reused HamaraStaff pattern)

## Status
- [x] Design sample (index.html) — awaiting approval
- [ ] video-proxy.php + Fal.ai integration
- [ ] Login + credits (MySQL)
- [ ] UPI top-up
- [ ] My Videos / history
