<?php

return [
  'my_scouting_unlock' => (int) env('SCOUTING_MY_SCOUTING_UNLOCK', 25),
  // Absolute total contributions required to complete stage 2 (not stage length alone).
  // Stage 2 length = your_impact_unlock - my_scouting_unlock (e.g. 125 - 25 = 100).
  // Local debug example: SCOUTING_MY_SCOUTING_UNLOCK=2 and SCOUTING_YOUR_IMPACT_UNLOCK=102.
  'your_impact_unlock' => (int) env('SCOUTING_YOUR_IMPACT_UNLOCK', 125),
];
