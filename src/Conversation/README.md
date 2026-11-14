# Conversation

Comments, questions and replies on a ticket. A question does not change the step; the asked person gets the turn. It owns the tables `cnv_message` and `cnv_reply`. Its Api is `ThreadQueries`. It may use the Api of Ticketing and Insight (`TurnProjector::refresh`).

Its events, in `Api\Event`, are `CommentPosted`, `QuestionAsked`, `QuestionAnswered`, `ReplyPosted` and `CommentResolved` (`ConversationEvent`, ids only, through the outbox to `async_notify`).

See the [module catalogue](../../docs/02-architecture/modules.md#conversation-cnv_).
