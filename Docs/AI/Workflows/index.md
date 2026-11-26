# AI Workflows

## Try the ticket-review demo

On **AI Workflows**, open **Demo starten** and pick a sample ticket.
No table row needs to be selected.

See the [three ticket-review examples](./ticket-review-example.md) for Mermaid
diagrams, sample inputs, step-by-step data handoffs, and agent JSON outputs.

Assign a model connection that supports structured JSON output to version `1`
of **Demo: Ticket triage**, **Demo: Ticket reply**, and **Demo: Ticket clarification**
under **AI Agents**. The agents have no tools and do not modify or send tickets.
The demo sends synthetic ticket text to the configured model.

The [installed demo graph](../../../Model/AI/Workflows/demo_ticket_review/01_AI_WORKFLOW.json)
runs the triage agent and routes its JSON `decision` with `SwitchStep`.
`draft_reply` runs the reply agent. For `ask_for_details`, `IfElseStep` allows
one retry when supplemental text is available: `CalculateData` passes that text
back to triage, otherwise the clarification agent asks a question.
In **Workflow runs**, filter for
`axenox.GenAI.demo_ticket_review` to see which steps ran.