<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="{
            state: $wire.$entangle(@js($getStatePath()), false),
            drawing: false,
            empty: true,
            ctx: null,
            // Se prepara al primer trazo: dentro de un modal, el lienzo recién tiene tamaño cuando ya se ve.
            setup() {
                const canvas = this.$refs.canvas
                const ratio = window.devicePixelRatio || 1

                if (this.ctx && canvas.width === Math.round(canvas.offsetWidth * ratio)) return

                canvas.width = Math.round(canvas.offsetWidth * ratio)
                canvas.height = Math.round(canvas.offsetHeight * ratio)
                this.ctx = canvas.getContext('2d')
                this.ctx.scale(ratio, ratio)
                this.ctx.lineWidth = 3
                this.ctx.lineCap = 'round'
                this.ctx.lineJoin = 'round'
                this.ctx.strokeStyle = '#0f172a'
            },
            point(event) {
                const rect = this.$refs.canvas.getBoundingClientRect()
                return { x: event.clientX - rect.left, y: event.clientY - rect.top }
            },
            start(event) {
                this.setup()
                this.drawing = true
                this.$refs.canvas.setPointerCapture(event.pointerId)
                const { x, y } = this.point(event)
                this.ctx.beginPath()
                this.ctx.moveTo(x, y)
                this.ctx.lineTo(x + 0.1, y + 0.1)
                this.ctx.stroke()
            },
            move(event) {
                if (! this.drawing) return
                const { x, y } = this.point(event)
                this.ctx.lineTo(x, y)
                this.ctx.stroke()
            },
            end() {
                if (! this.drawing) return
                this.drawing = false
                this.empty = false
                this.state = this.$refs.canvas.toDataURL('image/png')
            },
            clear() {
                const canvas = this.$refs.canvas
                this.ctx?.clearRect(0, 0, canvas.width, canvas.height)
                this.empty = true
                this.state = null
            },
        }"
        class="fi-signature-pad"
    >
        <div style="position: relative; border: 1px dashed var(--gray-400); border-radius: .75rem; background: #fff; overflow: hidden">
            <canvas
                x-ref="canvas"
                x-on:pointerdown.prevent="start($event)"
                x-on:pointermove.prevent="move($event)"
                x-on:pointerup="end()"
                x-on:pointercancel="end()"
                x-on:pointerleave="end()"
                style="display: block; width: 100%; height: 11rem; touch-action: none; cursor: crosshair"
                aria-label="Espacio para firmar"
            ></canvas>
            <span
                x-show="empty"
                style="position: absolute; inset: auto 0 1.2rem; text-align: center; color: var(--gray-400); font-size: .85rem; pointer-events: none"
            >Firme aquí con el dedo</span>
            <div style="position: absolute; left: 1.5rem; right: 1.5rem; bottom: 2.6rem; border-bottom: 1px solid var(--gray-300); pointer-events: none"></div>
        </div>
        <div style="display: flex; justify-content: flex-end; margin-top: .4rem">
            <button type="button" x-on:click="clear()" x-bind:disabled="empty" class="fi-link fi-size-sm" style="font-size: .85rem; font-weight: 600; color: var(--primary-600)" x-bind:style="empty && { opacity: .4 }">
                Borrar y firmar de nuevo
            </button>
        </div>
    </div>
</x-dynamic-component>
