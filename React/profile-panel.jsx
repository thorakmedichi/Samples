var ProfilePanel = React.createClass({
	getInitialState: function() {
		return {
			data: {
				customer: [],
				avatar: [],
				paymentMethods: {},
				statusOptions: {},
				neustar: {}
			},
			response: {
				type: '', 
				message: ''
			}
		};
	},

	getMap: function(lat, lng) {
		var basic = new MQA.Poi( {lat: lat, lng: lng} );
		var map = new MQA.TileMap({
			elt: document.getElementById('map'),       	/*ID of element on the page where you want the map added*/
			zoom: 9,                                  	/*initial zoom level of the map*/
			latLng: {lat: lat, lng:lng},  				/*center of map in latitude/longitude */
			mtype: 'map',                              	/*map type (map)*/
			bestFitMargin: 0,                          	/*margin offset from the map viewport when applying a bestfit on shapes*/
		});
		map.addShape(basic);

		MQA.withModule('largezoom', function() {
			map.addControl(
				new MQA.LargeZoom(),
				new MQA.MapCornerPlacement(MQA.MapCorner.TOP_LEFT, new MQA.Size(5,5))
			);
		});

		MQA.withModule('viewoptions', function() {
			map.addControl(
				new MQA.ViewOptions()
			);
		});
	},

	getCustomer: function() {
		$.ajax({
			url: this.props.url,
			dataType: 'json',
			cache: false,
			
			success: function(data) {
				this.setState({data: data});
			}.bind(this),
			
			error: function(xhr, status, err) {
				console.error(this.props.url, status, err.toString());
			}.bind(this)
		});
	},

	componentWillMount: function() {
		this.getCustomer();
	},

	componentDidUpdate: function() {
		var location = this.state.data.neustar;

		if (typeof location.latitude !== 'undefined'){
			this.getMap (location.latitude, location.longitude);
		}

		$(".nano").nanoScroller();
	},

	render: function() {
		var customer = this.state.data.customer;
		var paymentMethods = this.state.data.paymentMethods;
		var statusOptions = this.state.data.statusOptions;
		var location = this.state.data.neustar;
			location = location.length != 0 ? location : {};
			location.city = typeof location.city !== 'undefined' ? location.city.ucwords() : '';
			location.carrier = typeof location.carrier !== 'undefined' ? location.carrier.ucwords() : '';

		var canEditStatus = typeof statusOptions !== 'undefined' && (window.userRole == 'super_admin' || statusOptions[customer.status]) ? true : false;

		return (
			<div>
				<AlertPanel response={this.state.response} />

				<div className="media-body has-overflow">
					<div className="row Panel">
						<div className="col-xs-2">
							<i className="fa fa-user fa-lg"></i>
						</div>
						<div className="col-xs-10 text-lg text-semibold">
							<EditableField type="text" name="name" value={customer.name} url={this.props.url} title="Update Name" /><hr/>
						</div>
					</div>
					<div className="row Panel box">
						<div className="col-xs-2">
							<i className="fa fa-thumbs-up fa-lg"></i>
						</div>
						<div className="col-xs-10">
							<EditableField 
								type="select" 
								name="status" 
								value={customer.status_text} 
								valueInt={customer.status} 
								url={this.props.url} 
								title="Update Status" 
								options={statusOptions}
								disabled={canEditStatus ? false : true} /><hr/>
						</div>
					</div>
					<div className="row Panel">
						<div className="col-xs-2">
							<i className="fa fa-home fa-lg"></i>
						</div>
						<div className="col-xs-10">
							<address>
								<EditableField type="text" name="address" value={customer.address} url={this.props.url} title="Update Address" /><br/>
								<EditableField type="text" name="apt" value={customer.apt} url={this.props.url} title="Update Apartment" /><br/>
								<EditableField type="text" name="city" value={customer.city} url={this.props.url} title="Update City" />,&nbsp;
								<EditableField type="text" name="state" value={customer.state} url={this.props.url} title="Update State" />&nbsp;
								<EditableField type="text" name="zip" value={customer.zip} url={this.props.url} title="Update Postal Code" /><br/>
								<EditableField type="text" name="country" value={customer.country} url={this.props.url} title="Update Country" />
							</address>
						</div>
					</div>
					<div className="row Panel box">
						<div className="col-xs-2">
							<i className="fa fa-phone fa-lg"></i>
						</div>
						<div className="col-xs-10">
							<EditableField type="text" name="phone" value={customer.phone} url={this.props.url} title="Update Phone Number" />
						</div>
					</div>
					<div className="row Panel box">
						<div className="col-xs-2">
							<i className="fa fa-mobile fa-lg"></i>
						</div>
						<div className="col-xs-10">
							<EditableField type="text" name="mobile_phone" value={customer.mobile_phone} url={this.props.url} title="Update Mobile Number" />
						</div>
					</div>
					<div className="row Panel box">
						<div className="col-xs-2">
							<i className="fa fa-envelope fa-lg"></i>
						</div>
						<div className="col-xs-10 dont-break-out">
							<EditableField type="text" name="email" value={customer.email} url={this.props.url} title="Update Email" />
						</div>
					</div>

					<hr/>
					<div className="row box">
						<div className="col-xs-2">
							<i className="fa fa-tag fa-lg"></i>
						</div>
						<div className="col-xs-10">
							{customer.brand}
						</div>
					</div>

					{/* BEGIN IF
						Weird JSX Ternary expression to hide / show this block depending on user role */}
					{window.userRole == 'super_admin' ?
					<div className="row box">
						<div className="col-xs-2">
							<i className="fa fa-money fa-lg"></i>
						</div>
						<div className="col-xs-10">
							<EditableField type="select" name="payment_method" value={customer.payment_method} url={this.props.url} title="Update Payment Method" options={paymentMethods} />
						</div>
					</div>
					: ''}
					{/* END IF */}

					<hr/>
					<div className="row box">
						<div className="col-xs-2">
							<i className="fa fa-globe fa-lg"></i>
						</div>
						<div className="col-xs-10">
							{customer.ip}<br/>
							{location.city}, {location.state_code}
						</div>
					</div>
					<div className="row box">
						<div className="col-xs-2">
							<i className="fa fa-server fa-lg"></i>
						</div>
						<div className="col-xs-10">
							{location.carrier}
						</div>
					</div>
					<div className="row box">
						<div className="col-xs-2">
							<i className="fa fa-upload fa-lg"></i>
						</div>
						<div className="col-xs-10">
							{customer.speed_up} mbps
						</div>
					</div>
					<div className="row box">
						<div className="col-xs-2">
							<i className="fa fa-download fa-lg"></i>
						</div>
						<div className="col-xs-10">
							{customer.speed_down} mbps
						</div>
					</div>

					<div id="map"></div>
				</div>
			</div>
		);
	}
});

var profileUrl = "/customers/" + window.customerId;
ReactDOM.render(
	<ProfilePanel url={profileUrl} />,
	document.getElementById('profilePanel')
);
