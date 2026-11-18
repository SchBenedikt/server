import type { Post, PostVisibility } from '../types.js';
declare const _default: import("vue").DefineComponent<{
    inReplyToId?: string | null;
    defaultVisibility?: PostVisibility;
}, {}, {}, {}, {}, import("vue").ComponentOptionsMixin, import("vue").ComponentOptionsMixin, {
    created: (post: Post) => any;
}, string, import("vue").PublicProps, Readonly<{
    inReplyToId?: string | null;
    defaultVisibility?: PostVisibility;
}> & Readonly<{
    onCreated?: (post: Post) => any;
}>, {
    inReplyToId: string | null;
    defaultVisibility: PostVisibility;
}, {}, {}, {}, string, import("vue").ComponentProvideOptions, false, {}, any>;
export default _default;
