import { ref, onMounted, onBeforeUnmount } from 'vue';

// Reactive match for a media query — used to switch between the mobile
// (route-based) and desktop (three-pane) mail experiences.
export function useMediaQuery(query) {
    const matches = ref(false);
    let mql = null;
    const update = () => (matches.value = mql.matches);
    onMounted(() => {
        mql = window.matchMedia(query);
        update();
        mql.addEventListener('change', update);
    });
    onBeforeUnmount(() => mql?.removeEventListener('change', update));
    return matches;
}

export function useIsDesktop() {
    return useMediaQuery('(min-width: 1024px)');
}
