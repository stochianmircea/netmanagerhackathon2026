# Example policy

This is the text the model compiles. It is written by the site owner, in their own words —
not in a configuration language.

```
Cameras at the gate and the gate controller must stay alive no matter what.
Guest Wi-Fi is sacrificial.
If the battery drops below 30%, cut first what consumes a lot and matters little.
Critical stations are never touched.
```

The last line is not a suggestion to the model. Whatever the model returns, the validator
rejects any plan that names a protected target — see `src/validator.php`.
