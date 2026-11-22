# Layout settings in the Fields editor

Screenshots from the signed-in Joomla 6.2 browser session on the local f6 lab. The before build was c51e274 and the after build was 1bff9bd.

## Gallery settings after a field type change

Choose Image or Gallery on a new field, select Swiper and open its Desktop settings. Before the fix, the Parameters tab was blank. After the fix, the tab shows the layout name, accent and navigation colour controls, and the other Swiper settings.

[Before](field-layout-before.png) · [After](field-layout-after.png)

## Layout load failure

In the existing Kitchen Sink Image field, load Swiper and choose GLightbox. The browser test holds the request, checks that Save & Close sends no request, and returns HTTP 503. Before the fix, the dropdown showed GLightbox while the form contained Swiper settings. After the fix, it shows Swiper again and retains the matching settings. A subsequent GLightbox retry loads successfully.

[Before](layout-failure-before.png) · [After](layout-failure-after.png)

Chromium and Firefox both passed these workflows, including desktop and mobile settings for new fields and a second Text-to-Image change. The tests discarded their edits.
