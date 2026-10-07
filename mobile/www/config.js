// Appens indstillinger. siteUrl er det site, appen åbner.
// (dev.mitspor.dk ligger bag basic auth og kan ikke åbnes i appens WebView.)
// Ændres siteUrl, skal connect-src i index.html's Content-Security-Policy også rettes.
window.MITSPOR_CONFIG = {
    siteUrl: 'https://mitspor.dk'
};
