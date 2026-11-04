# Example policy — revision B

The same site owner, two weeks later, after a complaint and a cold morning. Nothing in the
code changed between revision A and this one. Only these words changed.

```
Cameras at the gate and the gate controller must stay alive no matter what.
Critical stations are never touched.
Guest Wi-Fi now carries the waiting room and is no longer sacrificial — leave it up if you can.
Cut entertainment and convenience first: media, signage, coffee, tool chargers.
Machines mid-job matter more than comfort, so leave the CNC and the dust extractor
for last, and only below 20%.
If the battery drops below 30%, act. Below 15%, cut everything that is not protected.
```

## Why this file exists

A fixed-threshold rule cannot read this. To follow revision B it has to be edited: the cut
order rewritten per site, a second threshold added at 20%, another at 15%. The model reads
it and re-plans.

`tools/bench.php --policy-shift` measures exactly that: how many of the 24 cases produce a
different plan under revision B. For the rule the answer is structurally 0 — it never saw
either file. The number to look at is not which approach saves more watts; it is which one
still does what the owner asked after the owner changed their mind.
