# Review

Review rounds with screenshots, pins and verdicts, including private draft rounds that are shared later. It owns the tables `rvw_round`, `rvw_screenshot`, `rvw_pin` and `rvw_check_item`. Its Api is `RoundQueries` and `RoundCommands`. It may use the Api of Ticketing, Media and Organization. `RoundMediaTarget` is its `MediaTargetAuthorizer` for rounds: Media asks it who may add an upload to a round.

See the [module catalogue](../../docs/02-architecture/modules.md#review-rvw_).
