<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-about">
            <a class="brand" href="{{ route('public.home') }}">Auroville Dry Herbarium</a>
            <p>Pressed specimens, field notes and collection records, open for research and discovery.</p>
        </div>
        <div>
            <h2 class="footer-heading">Address</h2>
            <address>Auroville Dry Herbarium<br>Auroville<br>Tamil Nadu, India</address>
        </div>
        <div>
            <h2 class="footer-heading">Contact</h2>
            <ul class="footer-list">
                @if (config('herbarium.contact_email'))
                    <li><a href="mailto:{{ config('herbarium.contact_email') }}">{{ config('herbarium.contact_email') }}</a></li>
                @endif
                <li><a href="{{ route('public.contact') }}">Send an enquiry</a></li>
            </ul>
        </div>
    </div>
    <div class="footer-base">
        <div class="container footer-base__inner">
            <p>&copy; {{ now()->year }} Auroville Dry Herbarium</p>
            <nav class="footer-nav" aria-label="Footer navigation">
                <a href="{{ route('public.home') }}">Home</a>
                <a href="{{ route('public.explore') }}">Explore</a>
                <a href="{{ route('public.about') }}">About</a>
                <a href="{{ route('public.contact') }}">Contact</a>
            </nav>
        </div>
    </div>
</footer>
