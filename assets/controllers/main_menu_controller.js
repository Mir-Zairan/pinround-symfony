// The phone main menu (base.html.twig) holds the Teams disclosure, which is a "menu" controller. Stimulus runs an action
// for an event from inside a nested controller of the same name only on that inner one, so with one name the outer menu
// never heard an Escape or a focus move from the Teams button. The same behaviour under its own name.
export { default } from './menu_controller.js';
