<?php
session_start();
if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$first = $USER_FIRST_NAME ?? ($_SESSION['first_name'] ?? '');
$last  = $USER_LAST_NAME ?? ($_SESSION['last_name'] ?? '');
$name  = trim($first . ' ' . $last);
if ($name === '' && !empty($_SESSION['email'])) {
    $name = $_SESSION['email'];
}
$role        = $USER_ROLE ?? ($_SESSION['role'] ?? '');
$isLoggedIn  = !empty($_SESSION['user_id']);
$displayRole = $role !== '' ? ucfirst((string) $role) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Peter Pang Fit | Elite Personal Training & Wellness Coaching</title>
    <meta name="description" content="Level up your workouts with Peter Pang Fit — personalized fitness coaching, strength training, mobility, and nutrition guidance for busy professionals and athletes.">
    <meta name="keywords" content="Peter Pang Fit, personal trainer, strength training, online coaching, gym, fitness, workouts, nutrition">
    <meta name="robots" content="index, follow">
    <meta name="subject" content="Fitness Training and Personal Coaching">
    <meta name="rating" content="General">
    <meta name="category" content="Health & Fitness">
    <meta property="og:title" content="Peter Pang Fit | Elite Personal Training">
    <meta property="og:description" content="Transform your body and mindset with customized training, accountability, and wellness support.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://peterpangfit.com/">
    <meta property="og:image" content="https://peterpangfit.com/assets/social/peterpangfit-hero.jpg">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Peter Pang Fit | Elite Personal Training">
    <meta name="twitter:description" content="Personal training, strength programs, and nutrition coaching tailored to your goals.">
    <meta name="twitter:image" content="https://peterpangfit.com/assets/social/peterpangfit-hero.jpg">
    <meta name="theme-color" content="#0f172a">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            color-scheme: dark;
            --bg: #05070d;
            --primary: #6ee7b7;
            --primary-dark: #22d3a2;
            --accent: #38bdf8;
            --text: #f8fafc;
            --muted: #cbd5f5;
            --surface: rgba(15, 23, 42, 0.65);
            --card: rgba(9, 14, 28, 0.9);
            --border: rgba(148, 163, 184, 0.18);
            --header-height: 76px;
            font-size: 16px;
        }
        *, *::before, *::after {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            font-family: 'Manrope', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--text);
            background: var(--bg);
            -webkit-font-smoothing: antialiased;
        }
        a {
            color: inherit;
            text-decoration: none;
        }
        img {
            max-width: 100%;
            display: block;
        }
        header.site-header {
            position: sticky;
            top: 0;
            z-index: 999;
            backdrop-filter: blur(18px);
            background: rgba(2, 6, 23, 0.92);
            border-bottom: 1px solid var(--border);
        }
        .header-inner {
            max-width: 1200px;
            margin: 0 auto;
            padding: 18px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .brand {
            font-size: clamp(1.25rem, 1.5vw + 1rem, 1.85rem);
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--text);
        }
        .nav-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .cta-login {
            padding: 10px 18px;
            font-weight: 600;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            color: #02131f;
            border: none;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .cta-login svg {
            width: 18px;
            height: 18px;
        }
        .cta-login:hover,
        .cta-login:focus {
            transform: translateY(-1px);
            box-shadow: 0 12px 40px rgba(56, 189, 248, 0.35);
        }
        .profile-chip {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: rgba(51, 65, 85, 0.4);
            border: 1px solid var(--border);
            padding: 10px 16px;
            border-radius: 999px;
        }
        .profile-chip .name {
            font-weight: 600;
        }
        .profile-chip .role {
            font-size: 0.85rem;
            color: var(--muted);
        }
        main {
            overflow: hidden;
        }
        .hero {
            position: relative;
            padding: calc(var(--header-height) + 40px) 24px 120px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 40px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .hero::before {
            content: "";
            position: absolute;
            inset: -120px -40px -40px;
            background: radial-gradient(circle at top left, rgba(56, 189, 248, 0.45), transparent 55%),
                        radial-gradient(circle at bottom right, rgba(110, 231, 183, 0.4), transparent 60%),
                        linear-gradient(160deg, rgba(15, 23, 42, 0.7), rgba(2, 6, 23, 0.95));
            z-index: -1;
            filter: blur(0px);
        }
        .hero-content {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.4em;
            font-size: 0.75rem;
            color: var(--muted);
        }
        .hero h1 {
            font-size: clamp(2.5rem, 4vw, 3.75rem);
            line-height: 1.05;
            margin: 0;
        }
        .hero h1 span {
            color: var(--primary);
        }
        .hero p {
            color: #dbeafe;
            font-size: clamp(1rem, 1.3vw + 1rem, 1.25rem);
            margin: 0;
        }
        .hero-cta {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 16px;
        }
        .btn-primary,
        .btn-secondary {
            padding: 14px 28px;
            border-radius: 999px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #02131f;
        }
        .btn-secondary {
            background: transparent;
            color: var(--text);
            border: 1px solid rgba(148, 163, 184, 0.45);
        }
        .btn-primary:hover,
        .btn-secondary:hover,
        .btn-primary:focus,
        .btn-secondary:focus {
            transform: translateY(-2px);
            box-shadow: 0 20px 45px rgba(94, 234, 212, 0.25);
        }
        .hero-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 18px;
            margin-top: auto;
        }
        .metric-card {
            background: rgba(15, 23, 42, 0.8);
            border-radius: 16px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            padding: 20px;
            text-align: center;
        }
        .metric-card h3 {
            margin: 0;
            font-size: 2rem;
            color: var(--primary);
        }
        .metric-card span {
            color: var(--muted);
            font-size: 0.95rem;
        }
        .cards-section {
            max-width: 1180px;
            margin: 0 auto;
            padding: 80px 24px;
            display: grid;
            gap: 32px;
        }
        .section-heading {
            max-width: 720px;
        }
        .section-heading h2 {
            font-size: clamp(2rem, 3vw, 2.75rem);
            margin-bottom: 16px;
        }
        .section-heading p {
            color: var(--muted);
            font-size: 1.05rem;
        }
        .grid {
            display: grid;
            gap: 24px;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        }
        .card {
            background: var(--card);
            border-radius: 22px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            padding: 28px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.4);
        }
        .card h3 {
            margin: 0;
            font-size: 1.4rem;
        }
        .card p {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }
        .card ul {
            margin: 0;
            padding-left: 18px;
            color: var(--muted);
            line-height: 1.6;
        }
        .split-section {
            display: grid;
            gap: 40px;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            align-items: center;
        }
        .badge-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .badge {
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(56, 189, 248, 0.15);
            border: 1px solid rgba(56, 189, 248, 0.3);
            color: var(--accent);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }
        .testimonials {
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.8) 0%, rgba(2, 6, 23, 1) 100%);
        }
        blockquote {
            margin: 0;
            font-size: 1.1rem;
            line-height: 1.7;
            color: #e0f2fe;
        }
        cite {
            display: block;
            margin-top: 12px;
            color: var(--muted);
            font-style: normal;
            letter-spacing: 0.08em;
        }
        .cta-banner {
            max-width: 980px;
            margin: 0 auto 120px;
            padding: 48px;
            border-radius: 28px;
            background: linear-gradient(140deg, rgba(56, 189, 248, 0.15), rgba(110, 231, 183, 0.15));
            border: 1px solid rgba(94, 234, 212, 0.2);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 24px;
            align-items: center;
        }
        footer {
            background: #020817;
            border-top: 1px solid rgba(148, 163, 184, 0.16);
            padding: 40px 24px;
        }
        .footer-inner {
            max-width: 1180px;
            margin: 0 auto;
            display: grid;
            gap: 32px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        .footer-column h4 {
            margin: 0 0 16px;
            font-size: 1.1rem;
        }
        .footer-column p {
            margin: 0;
            color: var(--muted);
            line-height: 1.6;
        }
        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 12px;
        }
        .footer-links a {
            color: var(--muted);
            font-size: 0.95rem;
        }
        .footer-bottom {
            margin-top: 32px;
            text-align: center;
            color: rgba(148, 163, 184, 0.6);
            font-size: 0.85rem;
        }
        @media (max-width: 720px) {
            header.site-header {
                position: fixed;
                width: 100%;
            }
            body {
                padding-top: var(--header-height);
            }
            .hero {
                padding-top: 40px;
                padding-bottom: 80px;
            }
            .hero-cta {
                flex-direction: column;
                align-items: stretch;
            }
            .cta-banner {
                padding: 32px;
            }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }
    </style>
</head>
<body>
<header class="site-header" aria-label="Main site header">
    <div class="header-inner">
        <a href="index.php" class="brand">Peter Pang Fit</a>
        <div class="nav-actions">
            <?php if ($isLoggedIn): ?>
                <div class="profile-chip" aria-label="Logged in user">
                    <div class="name"><?php echo h($name ?: 'Account'); ?></div>
                    <?php if ($displayRole): ?>
                        <div class="role"><?php echo h($displayRole); ?></div>
                    <?php endif; ?>
                </div>
                <a href="dashboard.php" class="cta-login" style="background: rgba(56, 189, 248, 0.16); color: var(--text); border: 1px solid rgba(56, 189, 248, 0.4);">
                    <span>Go to Portal</span>
                </a>
            <?php else: ?>
                <a href="login.php" class="cta-login">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                        <polyline points="10 17 15 12 10 7" />
                        <line x1="15" y1="12" x2="3" y2="12" />
                    </svg>
                    <span>Client Login</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>
<main>
    <section class="hero" id="top">
        <div class="hero-content">
            <span class="eyebrow">Strength · Performance · Longevity</span>
            <h1>Professional coaching for <span>high-performing bodies</span> and unstoppable confidence.</h1>
            <p>Peter Pang Fit blends science-backed programming, mindful coaching, and relentless accountability to help you build a resilient, athletic body. Crush your workouts in the gym, from home, or on the road with guidance tailored to your goals.</p>
            <div class="hero-cta">
                <a class="btn-primary" href="register.php">Start Your Assessment</a>
                <a class="btn-secondary" href="#programs">Explore Training Tracks</a>
            </div>
            <div class="hero-metrics" role="list" aria-label="Key client metrics">
                <div class="metric-card" role="listitem">
                    <h3>350+</h3>
                    <span>Transformations since 2016</span>
                </div>
                <div class="metric-card" role="listitem">
                    <h3>92%</h3>
                    <span>Clients hitting PRs within 12 weeks</span>
                </div>
                <div class="metric-card" role="listitem">
                    <h3>24/7</h3>
                    <span>Accountability from your trainer</span>
                </div>
            </div>
        </div>
        <div class="hero-visual" aria-hidden="true">
            <picture>
                <source srcset="https://images.unsplash.com/photo-1571019614184-0f0150280bd1?auto=format&fit=crop&w=900&q=80" media="(min-width: 768px)">
                <img src="https://images.unsplash.com/photo-1517836357463-d25dfeac3438?auto=format&fit=crop&w=700&q=80" alt="Athlete training with a coach" style="width:100%; border-radius: 28px; border: 1px solid rgba(148,163,184,0.25); box-shadow: 0 32px 80px rgba(15,23,42,0.55); object-fit: cover;">
            </picture>
        </div>
    </section>

    <section class="cards-section" id="programs">
        <div class="section-heading">
            <div class="badge-row">
                <span class="badge">Personal Training</span>
                <span class="badge">Strength & Conditioning</span>
                <span class="badge">Nutrition Strategy</span>
            </div>
            <h2>Tailored programming for every season of your fitness journey.</h2>
            <p>Whether you want to build lean muscle, improve sport performance, or reset your relationship with movement, Peter designs periodized workouts and recovery protocols around your schedule, equipment, and lifestyle.</p>
        </div>
        <div class="grid">
            <article class="card" aria-labelledby="card1">
                <h3 id="card1">Hybrid Coaching</h3>
                <p>Blend in-person sessions with remote coaching to keep your training consistent no matter where you are. Includes video feedback, weekly check-ins, and real-time adjustments.</p>
                <ul>
                    <li>Customized strength programming</li>
                    <li>Form reviews and movement screens</li>
                    <li>Habit coaching for sustainable change</li>
                </ul>
            </article>
            <article class="card" aria-labelledby="card2">
                <h3 id="card2">Athlete Performance</h3>
                <p>Unlock explosive power, agility, and endurance with sport-specific conditioning. Built for competitors, weekend warriors, and corporate athletes alike.</p>
                <ul>
                    <li>Force plate & mobility assessments</li>
                    <li>Speed, agility, and reactive drills</li>
                    <li>Integrated recovery & soft tissue routines</li>
                </ul>
            </article>
            <article class="card" aria-labelledby="card3">
                <h3 id="card3">Metabolic Reset</h3>
                <p>Crush plateaus with metabolic conditioning and nutrition alignment. Ideal for busy professionals seeking fat loss, energy optimization, and strength gains.</p>
                <ul>
                    <li>Meal planning + macro coaching</li>
                    <li>Hormonal health & sleep hygiene tips</li>
                    <li>Community accountability pods</li>
                </ul>
            </article>
        </div>
    </section>

    <section class="cards-section split-section" id="about">
        <div>
            <h2>Meet Peter — your coach for smarter, stronger movement.</h2>
            <p>Peter Pang is a certified strength and conditioning specialist (CSCS) and precision nutrition coach with a decade of experience training executives, athletes, and first responders. He combines data-driven programming with mindset coaching to get clients moving better and feeling more confident in and out of the gym.</p>
            <p>From kettlebells to calisthenics, Peter equips you with the education, programming, and accountability necessary to build long-term fitness habits.</p>
            <div class="hero-cta">
                <a class="btn-primary" href="register.php">Book a Discovery Call</a>
                <a class="btn-secondary" href="mailto:coach@peterpangfit.com">Email Coach Peter</a>
            </div>
        </div>
        <div>
            <div class="card" style="background: rgba(15, 23, 42, 0.85); border: 1px solid rgba(148, 163, 184, 0.25);">
                <h3>Coaching highlights</h3>
                <ul>
                    <li>ACSM & CSCS Certified Trainer</li>
                    <li>Precision Nutrition Level 2 Coach</li>
                    <li>Mobility and corrective exercise specialist</li>
                    <li>Trusted by tech founders, pro gamers, and first responders</li>
                    <li>Remote-friendly programs with app-based habit tracking</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="cards-section testimonials" id="testimonials">
        <div class="section-heading">
            <h2>Members building muscle, confidence, and momentum.</h2>
            <p>Real stories from clients who trusted the process and put in the work — and now enjoy stronger bodies, better posture, and more energy for life.</p>
        </div>
        <div class="grid">
            <article class="card" style="background: rgba(30, 41, 59, 0.55);">
                <blockquote>
                    “Peter dialed in my training for a Spartan race while helping me drop 18 pounds. The app check-ins and video feedback kept me accountable every single week.”
                </blockquote>
                <cite>— Renee, Product Manager & OCR Athlete</cite>
            </article>
            <article class="card" style="background: rgba(30, 41, 59, 0.55);">
                <blockquote>
                    “My back pain is gone, strength is up, and I finally feel confident stepping into the weight room. Peter’s coaching made fitness part of my lifestyle instead of a chore.”
                </blockquote>
                <cite>— Marco, Software Engineer</cite>
            </article>
            <article class="card" style="background: rgba(30, 41, 59, 0.55);">
                <blockquote>
                    “From nutrition coaching to stress management, Peter helped me balance marathon training with a demanding travel schedule. I set a new personal best!”
                </blockquote>
                <cite>— Danielle, Consultant & Marathoner</cite>
            </article>
        </div>
    </section>

    <section class="cards-section split-section" id="faq">
        <div class="section-heading">
            <h2>Frequently asked questions</h2>
            <p>Everything you need to know before starting your next training block.</p>
        </div>
        <div class="grid">
            <article class="card">
                <h3>Where do sessions happen?</h3>
                <p>Training is offered in-studio across Vancouver and Burnaby, on-location at your corporate gym, or remotely through the Peter Pang Fit mobile portal with video coaching.</p>
            </article>
            <article class="card">
                <h3>Do I need a full gym?</h3>
                <p>No. Programs can be customized for garage gyms, hotel setups, or bodyweight-only workouts. Peter provides equipment recommendations that match your goals and space.</p>
            </article>
            <article class="card">
                <h3>How is nutrition handled?</h3>
                <p>You’ll receive macro targets, meal templates, hydration goals, and habit coaching. Peter works with registered dietitians for specialized needs.</p>
            </article>
        </div>
    </section>

    <section class="cta-banner" id="cta">
        <div>
            <h2 style="margin:0 0 12px; font-size: clamp(1.8rem, 3vw, 2.4rem);">Ready to move, lift, and feel better?</h2>
            <p style="margin:0; color: var(--muted);">Join the Peter Pang Fit community for structured workouts, weekly accountability, and science-backed recovery strategies that keep you pushing forward.</p>
        </div>
        <div class="hero-cta" style="margin:0; justify-content:flex-end;">
            <a class="btn-primary" href="register.php">Apply Now</a>
            <a class="btn-secondary" href="login.php">Member Login</a>
        </div>
    </section>
</main>
<footer>
    <div class="footer-inner">
        <div class="footer-column">
            <h4>About Peter Pang Fit</h4>
            <p>Premium personal training for busy professionals seeking a stronger, healthier lifestyle. Functional strength, mobility, and performance coaching designed for sustainable results.</p>
        </div>
        <div class="footer-column">
            <h4>Quick Links</h4>
            <ul class="footer-links">
                <li><a href="#programs">Programs</a></li>
                <li><a href="#about">Meet Peter</a></li>
                <li><a href="#testimonials">Success Stories</a></li>
                <li><a href="#faq">FAQ</a></li>
                <li><a href="login.php">Client Login</a></li>
            </ul>
        </div>
        <div class="footer-column">
            <h4>Contact</h4>
            <p>coach@peterpangfit.com<br>Vancouver, BC<br>+1 (604) 555-0199</p>
        </div>
    </div>
    <div class="footer-bottom">
        &copy; <?php echo date('Y'); ?> Peter Pang Fit. All rights reserved. | Train smart. Move strong. Live energized.
    </div>
</footer>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "HealthClub",
    "name": "Peter Pang Fit",
    "url": "https://peterpangfit.com/",
    "description": "Personal training, strength coaching, and nutrition guidance from Peter Pang Fit.",
    "address": {
        "@type": "PostalAddress",
        "addressLocality": "Vancouver",
        "addressRegion": "BC",
        "addressCountry": "CA"
    },
    "telephone": "+1-604-555-0199",
    "areaServed": "North America",
    "sameAs": [
        "https://www.instagram.com/peterpangfit",
        "https://www.facebook.com/peterpangfit"
    ],
    "founder": {
        "@type": "Person",
        "name": "Peter Pang"
    }
}
</script>
</body>
</html>
