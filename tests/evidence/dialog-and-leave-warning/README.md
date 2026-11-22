# Before-and-after browser evidence

These captures use isolated pages running the exact FLEXIcontent scripts and Joomla 6 dialog assets. The result panels report what the browsers observed.

## My Files picker

The fixture opens the native Joomla dialog and removes the transient node that stood immediately after the field in the editor. Closing the dialog reproduces the obsolete insertion target at PR #1421 head `4a32e918`. The patched run restores the selected file in the form and invokes the close callback once.

| Before | After |
| --- | --- |
| [![The file field is missing after My Files closes](my-files-before.png)](my-files-before.png) | [![The file field and its selected value return after My Files closes](my-files-after.png)](my-files-after.png) |

## Item editor leave warning

The fixture opens an untouched item, removes an empty Banner poster input, adds a zero upload count, and clicks Back to Items. Before the fix, Playwright records the browser's native `beforeunload` event and dismisses the prompt. The screenshot shows the editor afterward with the captured result. After the fix, the browser navigates to the list without raising the prompt.

| Before | After |
| --- | --- |
| [![The unchanged item stays open after a false leave prompt](leave-warning-before.png)](leave-warning-before.png) | [![The browser returns to Items without a leave prompt](leave-warning-after.png)](leave-warning-after.png) |

[Chromium results](chromium-evidence.json) · [Firefox results](firefox-evidence.json)
